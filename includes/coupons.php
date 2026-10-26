<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Promotional Coupon Validation & Discount Calculations
 * 
 * PHP version 8.x
 */

declare(strict_types=1);

/**
 * Validate a promotional coupon code against the database.
 * 
 * Implements strict server-side checks for:
 * 1. Coupon existence in database
 * 2. Active status flag (is_active === 1)
 * 3. Start date validity (NOW() >= valid_from)
 * 4. Expiration date window (NOW() <= valid_until)
 * 5. Platform usage limits (used_count < usage_limit / max_uses)
 * 6. Minimum order subtotal qualification (orderSubtotal >= min_order_amount)
 *
 * @param PDO $pdo Active PDO database connection.
 * @param string $couponCode The voucher code to validate.
 * @param float $orderSubtotal Current portions subtotal in LKR.
 * @return array{
 *     valid: bool,
 *     error: string|null,
 *     coupon: array<string, mixed>|null,
 *     discount_amount: float
 * }
 */
function validate_coupon(PDO $pdo, string $couponCode, float $orderSubtotal): array {
    $code = strtoupper(trim($couponCode));

    // Initial format check
    if (empty($code)) {
        return [
            'valid'           => false,
            'error'           => 'Please enter a promotional coupon code.',
            'coupon'          => null,
            'discount_amount' => 0.00,
        ];
    }

    if ($orderSubtotal < 0.0) {
        return [
            'valid'           => false,
            'error'           => 'Invalid order subtotal provided.',
            'coupon'          => null,
            'discount_amount' => 0.00,
        ];
    }

    try {
        // Query coupon record using PDO prepared statement
        $stmt = $pdo->prepare('
            SELECT coupon_id, code, discount_type, discount_value, min_order_amount,
                   max_discount_amount, usage_limit, usage_limit AS max_uses,
                   used_count, valid_from, valid_until, is_active
            FROM coupons
            WHERE UPPER(code) = UPPER(:code)
            LIMIT 1
        ');
        $stmt->execute([':code' => $code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        // 1. Existence Check
        if (!$coupon) {
            return [
                'valid'           => false,
                'error'           => sprintf('Coupon code "%s" does not exist.', htmlspecialchars($code, ENT_QUOTES, 'UTF-8')),
                'coupon'          => null,
                'discount_amount' => 0.00,
            ];
        }

        // 2. Active Status Flag Check
        if ((int)$coupon['is_active'] !== 1) {
            return [
                'valid'           => false,
                'error'           => sprintf('The coupon "%s" is currently inactive or has been disabled.', htmlspecialchars($code, ENT_QUOTES, 'UTF-8')),
                'coupon'          => $coupon,
                'discount_amount' => 0.00,
            ];
        }

        $now = date('Y-m-d H:i:s');

        // 3. Start Date Validity Check
        if ($now < (string)$coupon['valid_from']) {
            $formattedStart = date('M j, Y', strtotime((string)$coupon['valid_from']));
            return [
                'valid'           => false,
                'error'           => sprintf('The coupon "%s" is not valid yet. Promotion begins on %s.', htmlspecialchars($code, ENT_QUOTES, 'UTF-8'), $formattedStart),
                'coupon'          => $coupon,
                'discount_amount' => 0.00,
            ];
        }

        // 4. Expiration Date Check
        if ($now > (string)$coupon['valid_until']) {
            $formattedExpiry = date('M j, Y', strtotime((string)$coupon['valid_until']));
            return [
                'valid'           => false,
                'error'           => sprintf('The coupon "%s" expired on %s.', htmlspecialchars($code, ENT_QUOTES, 'UTF-8'), $formattedExpiry),
                'coupon'          => $coupon,
                'discount_amount' => 0.00,
            ];
        }

        // 5. Remaining Usage Limits Check (used_count vs max_uses / usage_limit)
        $maxUses = ($coupon['usage_limit'] !== null) ? (int)$coupon['usage_limit'] : null;
        $usedCount = (int)$coupon['used_count'];

        if ($maxUses !== null && $usedCount >= $maxUses) {
            return [
                'valid'           => false,
                'error'           => sprintf('The coupon "%s" has reached its maximum redemption limit of %d uses.', htmlspecialchars($code, ENT_QUOTES, 'UTF-8'), $maxUses),
                'coupon'          => $coupon,
                'discount_amount' => 0.00,
            ];
        }

        // 6. Minimum Order Value Requirement Check
        $minOrderAmount = (float)$coupon['min_order_amount'];
        if ($orderSubtotal < $minOrderAmount) {
            return [
                'valid'           => false,
                'error'           => sprintf(
                    'The coupon "%s" requires a minimum order subtotal of LKR %s. Your current subtotal is LKR %s.',
                    htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
                    number_format($minOrderAmount, 2),
                    number_format($orderSubtotal, 2)
                ),
                'coupon'          => $coupon,
                'discount_amount' => 0.00,
            ];
        }

        // 7. Calculate Discount Amount
        $discountAmount = calculate_coupon_discount($coupon, $orderSubtotal);

        return [
            'valid'           => true,
            'error'           => null,
            'coupon'          => [
                'coupon_id'           => (int)$coupon['coupon_id'],
                'code'                => (string)$coupon['code'],
                'discount_type'       => (string)$coupon['discount_type'],
                'discount_value'      => (float)$coupon['discount_value'],
                'min_order_amount'    => $minOrderAmount,
                'max_discount_amount' => $coupon['max_discount_amount'] !== null ? (float)$coupon['max_discount_amount'] : null,
                'usage_limit'         => $maxUses,
                'max_uses'            => $maxUses,
                'used_count'          => $usedCount,
            ],
            'discount_amount' => $discountAmount,
        ];

    } catch (PDOException $e) {
        error_log('[Coupon Error] validate_coupon failed for code "' . $couponCode . '": ' . $e->getMessage());
        return [
            'valid'           => false,
            'error'           => 'A technical issue occurred while verifying your coupon. Please try again.',
            'coupon'          => null,
            'discount_amount' => 0.00,
        ];
    }
}

/**
 * Compute the discount amount in LKR for a qualified coupon.
 *
 * @param array<string, mixed> $coupon Coupon record array.
 * @param float $subtotal Order subtotal in LKR.
 * @param float $deliveryFee Standard delivery fee in LKR.
 * @return float Calculated discount in LKR.
 */
function calculate_coupon_discount(array $coupon, float $subtotal, float $deliveryFee = 350.00): float {
    $code = strtoupper((string)($coupon['code'] ?? ''));

    // Special free delivery promotion
    if ($code === 'FREEDELIVERY') {
        return $deliveryFee;
    }

    $discountType = (string)($coupon['discount_type'] ?? 'fixed');
    $discountValue = (float)($coupon['discount_value'] ?? 0.0);
    $maxDiscount = isset($coupon['max_discount_amount']) && $coupon['max_discount_amount'] !== null
        ? (float)$coupon['max_discount_amount']
        : null;

    if ($discountType === 'percentage') {
        $calculated = ($subtotal * $discountValue) / 100.0;
        if ($maxDiscount !== null && $calculated > $maxDiscount) {
            $calculated = $maxDiscount;
        }
        return min($calculated, $subtotal);
    }

    // Fixed amount discount
    return min($discountValue, $subtotal);
}

/**
 * Increment the used_count column atomically when an order is finalized.
 *
 * @param PDO $pdo Active PDO connection.
 * @param int $couponId Target coupon ID.
 * @return bool True if updated successfully.
 */
function increment_coupon_usage(PDO $pdo, int $couponId): bool {
    try {
        $stmt = $pdo->prepare('
            UPDATE coupons
            SET used_count = used_count + 1
            WHERE coupon_id = :coupon_id
        ');
        return $stmt->execute([':coupon_id' => $couponId]);
    } catch (PDOException $e) {
        error_log('[Coupon Error] increment_coupon_usage failed for ID ' . $couponId . ': ' . $e->getMessage());
        return false;
    }
}
