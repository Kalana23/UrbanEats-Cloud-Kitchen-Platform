<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Stock & Inventory Management Helper Functions
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

/**
 * Transaction-safe stock reduction function.
 * 
 * Used by Component C during order checkout and kitchen operations.
 * Strictly guarantees stock never drops below zero using conditional UPDATE.
 *
 * @param PDO $pdo Active database connection
 * @param int $dish_id Target dish ID
 * @param int $qty Quantity to deduct (must be > 0)
 * @param int|null $user_id ID of the customer or staff triggering the reduction
 * @param string $reason Optional reason or order reference
 * @return bool True if deduction succeeded, false if insufficient stock
 */
function reduce_stock(
    PDO $pdo, 
    int $dish_id, 
    int $qty, 
    ?int $user_id = null, 
    string $reason = 'Order checkout deduction'
): bool {
    if ($qty <= 0) {
        return false;
    }

    $isInternalTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isInternalTransaction = true;
    }

    try {
        // Atomic conditional decrement: refuses to execute if current_stock < qty
        // Note: Using distinct parameter names (:deduct_qty and :min_qty) for compatibility with PDO::ATTR_EMULATE_PREPARES => false
        $stmt = $pdo->prepare('
            UPDATE inventory 
            SET current_stock = current_stock - :deduct_qty 
            WHERE dish_id = :dish_id 
              AND current_stock >= :min_qty
        ');
        $stmt->execute([
            ':deduct_qty' => $qty,
            ':dish_id'    => $dish_id,
            ':min_qty'    => $qty,
        ]);

        if ($stmt->rowCount() !== 1) {
            // Insufficient stock or dish not in inventory
            if ($isInternalTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        // Record stock movement in the audit trail
        $movementStmt = $pdo->prepare('
            INSERT INTO stock_movements (dish_id, user_id, change_qty, movement_type, reason, created_at)
            VALUES (:dish_id, :user_id, :change_qty, :movement_type, :reason, NOW())
        ');
        $movementStmt->execute([
            ':dish_id'       => $dish_id,
            ':user_id'       => $user_id,
            ':change_qty'    => -$qty,
            ':movement_type' => 'order',
            ':reason'        => $reason,
        ]);

        if ($isInternalTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($isInternalTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Stock Error] reduce_stock failed for dish ' . $dish_id . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Record a manual stock adjustment (restock, wastage, or audit adjustment).
 *
 * @param PDO $pdo Active PDO instance
 * @param int $dish_id Target dish ID
 * @param int $change_qty Positive or negative stock change
 * @param string $movement_type 'restock', 'wastage', 'adjustment'
 * @param string $reason Staff explanation
 * @param int|null $user_id Staff user ID making the change
 * @return bool True on success, false on failure
 */
function record_stock_adjustment(
    PDO $pdo,
    int $dish_id,
    int $change_qty,
    string $movement_type,
    string $reason,
    ?int $user_id = null
): bool {
    $allowedTypes = ['restock', 'wastage', 'adjustment'];
    if (!in_array($movement_type, $allowedTypes, true)) {
        return false;
    }

    if ($change_qty === 0) {
        return false;
    }

    $isInternalTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isInternalTransaction = true;
    }

    try {
        if ($change_qty < 0) {
            $absQty = abs($change_qty);
            $stmt = $pdo->prepare('
                UPDATE inventory 
                SET current_stock = current_stock - :deduct_qty 
                WHERE dish_id = :dish_id AND current_stock >= :min_qty
            ');
            $stmt->execute([
                ':deduct_qty' => $absQty,
                ':dish_id'    => $dish_id,
                ':min_qty'    => $absQty,
            ]);
        } else {
            $stmt = $pdo->prepare('
                UPDATE inventory 
                SET current_stock = current_stock + :add_qty 
                WHERE dish_id = :dish_id
            ');
            $stmt->execute([
                ':add_qty' => $change_qty,
                ':dish_id' => $dish_id,
            ]);
        }

        if ($stmt->rowCount() !== 1) {
            if ($isInternalTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        $movementStmt = $pdo->prepare('
            INSERT INTO stock_movements (dish_id, user_id, change_qty, movement_type, reason, created_at)
            VALUES (:dish_id, :user_id, :change_qty, :movement_type, :reason, NOW())
        ');
        $movementStmt->execute([
            ':dish_id'       => $dish_id,
            ':user_id'       => $user_id,
            ':change_qty'    => $change_qty,
            ':movement_type' => $movement_type,
            ':reason'        => $reason,
        ]);

        if ($isInternalTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($isInternalTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Stock Error] record_stock_adjustment failed for dish ' . $dish_id . ': ' . $e->getMessage());
        return false;
    }
}
