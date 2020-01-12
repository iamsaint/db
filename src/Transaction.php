<?php

namespace Yiisoft\Db;

use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;

/**
 * Transaction represents a DB transaction.
 *
 * It is usually created by calling {@see Connection::beginTransaction()}.
 *
 * The following code is a typical example of using transactions (note that some DBMS may not support transactions):
 *
 * ```php
 * $transaction = $connection->beginTransaction();
 * try {
 *     $connection->createCommand($sql1)->execute();
 *     $connection->createCommand($sql2)->execute();
 *     //.... other SQL executions
 *     $transaction->commit();
 * } catch (\Throwable $e) {
 *     $transaction->rollBack();
 *     throw $e;
 * }
 * ```
 *
 * @property bool $isActive Whether this transaction is active. Only an active transaction can {@see commit()} or
 * {@see rollBack()}. This property is read-only.
 * @property string $isolationLevel The transaction isolation level to use for this transaction. This can be
 * one of {@see READ_UNCOMMITTED}, {@see READ_COMMITTED}, {@see REPEATABLE_READ} and {@see SERIALIZABLE} but also a
 * string containing DBMS specific syntax to be used after `SET TRANSACTION ISOLATION LEVEL`. This property is
 * write-only.
 * @property int $level The current nesting level of the transaction. This property is read-only.
 */
class Transaction
{
    /**
     * A constant representing the transaction isolation level `READ UNCOMMITTED`.
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    const READ_UNCOMMITTED = 'READ UNCOMMITTED';

    /**
     * A constant representing the transaction isolation level `READ COMMITTED`.
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    const READ_COMMITTED = 'READ COMMITTED';

    /**
     * A constant representing the transaction isolation level `REPEATABLE READ`.
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    const REPEATABLE_READ = 'REPEATABLE READ';

    /**
     * A constant representing the transaction isolation level `SERIALIZABLE`.
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    const SERIALIZABLE = 'SERIALIZABLE';

    /**
     * @var Connection the database connection that this transaction is associated with.
     */
    public Connection $db;

    /**
     * @var int the nesting level of the transaction. 0 means the outermost level.
     */
    private int $level = 0;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Returns a value indicating whether this transaction is active.
     *
     * @return bool whether this transaction is active. Only an active transaction can {@see commit()} or {rollBack()}.
     */
    public function getIsActive(): bool
    {
        return $this->level > 0 && $this->db && $this->db->getIsActive();
    }

    /**
     * Begins a transaction.
     *
     * @param string|null $isolationLevel The {@see isolation level}[] to use for this transaction.
     * This can be one of {@see READ_UNCOMMITTED}, {@see READ_COMMITTED}, {@see REPEATABLE_READ} and {@see SERIALIZABLE}
     * but also a string containing DBMS specific syntax to be used after `SET TRANSACTION ISOLATION LEVEL`.
     *
     * If not specified (`null`) the isolation level will not be set explicitly and the DBMS default will be used.
     *
     * > Note: This setting does not work for PostgreSQL, where setting the isolation level before the transaction
     * has no effect. You have to call {@see setIsolationLevel()} in this case after the transaction has started.
     *
     * > Note: Some DBMS allow setting of the isolation level only for the whole connection so subsequent transactions
     * may get the same isolation level even if you did not specify any. When using this feature
     * you may need to set the isolation level for all transactions explicitly to avoid conflicting settings.
     * At the time of this writing affected DBMS are MSSQL and SQLite.
     *
     * [isolation level]: http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     *
     *
     * @throws InvalidConfigException if {@see db} is `null`
     * @throws NotSupportedException if the DBMS does not support nested transactions
     *
     * @throws \Exception if DB connection fails
     */
    public function begin(?string $isolationLevel = null)
    {
        if ($this->db === null) {
            throw new InvalidConfigException('Transaction::db must be set.');
        }

        $this->db->open();

        if ($this->level === 0) {
            if ($isolationLevel !== null) {
                $this->db->getSchema()->setTransactionIsolationLevel($isolationLevel);
            }

            //Yii::debug('Begin transaction'.($isolationLevel ? ' with isolation level '.$isolationLevel : ''), __METHOD__);

            //$this->db->trigger(Connection::EVENT_BEGIN_TRANSACTION);
            $this->db->getPDO()->beginTransaction();
            $this->level = 1;

            return;
        }

        $schema = $this->db->getSchema();

        if ($schema->supportsSavepoint()) {
            //Yii::debug('Set savepoint '.$this->_level, __METHOD__);
            $schema->createSavepoint('LEVEL'.$this->level);
        } else {
            //Yii::info('Transaction not started: nested transaction not supported', __METHOD__);

            throw new NotSupportedException('Transaction not started: nested transaction not supported.');
        }

        $this->level++;
    }

    /**
     * Commits a transaction.
     *
     * @throws Exception if the transaction is not active
     */
    public function commit()
    {
        if (!$this->getIsActive()) {
            throw new Exception('Failed to commit transaction: transaction was inactive.');
        }

        $this->level--;
        if ($this->level === 0) {
            //Yii::debug('Commit transaction', __METHOD__);
            $this->db->getPDO()->commit();
            //$this->db->trigger(Connection::EVENT_COMMIT_TRANSACTION);

            return;
        }

        $schema = $this->db->getSchema();
        if ($schema->supportsSavepoint()) {
            //Yii::debug('Release savepoint '.$this->_level, __METHOD__);
            $schema->releaseSavepoint('LEVEL'.$this->level);
        } else {
            //Yii::info('Transaction not committed: nested transaction not supported', __METHOD__);
        }
    }

    /**
     * Rolls back a transaction.
     */
    public function rollBack()
    {
        if (!$this->getIsActive()) {
            // do nothing if transaction is not active: this could be the transaction is committed
            // but the event handler to "commitTransaction" throw an exception
            return;
        }

        $this->level--;
        if ($this->level === 0) {
            //Yii::debug('Roll back transaction', __METHOD__);
            $this->db->getPDO()->rollBack();
            //$this->db->trigger(Connection::EVENT_ROLLBACK_TRANSACTION);

            return;
        }

        $schema = $this->db->getSchema();
        if ($schema->supportsSavepoint()) {
            //Yii::debug('Roll back to savepoint '.$this->_level, __METHOD__);
            $schema->rollBackSavepoint('LEVEL'.$this->level);
        } else {
            //Yii::info('Transaction not rolled back: nested transaction not supported', __METHOD__);
        }
    }

    /**
     * Sets the transaction isolation level for this transaction.
     *
     * This method can be used to set the isolation level while the transaction is already active.
     * However this is not supported by all DBMS so you might rather specify the isolation level directly
     * when calling {@see begin()}.
     *
     * @param string $level The transaction isolation level to use for this transaction.
     * This can be one of {@see READ_UNCOMMITTED}, {@see READ_COMMITTED}, {@see REPEATABLE_READ} and {@see SERIALIZABLE}
     * but also a string containing DBMS specific syntax to be used after `SET TRANSACTION ISOLATION LEVEL`.
     *
     * @throws \Exception if the transaction is not active
     *
     * @see http://en.wikipedia.org/wiki/Isolation_%28database_systems%29#Isolation_levels
     */
    public function setIsolationLevel(string $level)
    {
        if (!$this->getIsActive()) {
            throw new \Exception('Failed to set isolation level: transaction was inactive.');
        }

        //Yii::debug('Setting transaction isolation level to '.$level, __METHOD__);
        $this->db->getSchema()->setTransactionIsolationLevel($level);
    }

    /**
     * @return int The current nesting level of the transaction.
     */
    public function getLevel(): int
    {
        return $this->level;
    }
}
