<?php

require_once __DIR__ . '/recharge_preview_service.php';

final class RechargeService
{
    public static function simulate(
        PDO $pdo,
        array $device,
        string $username,
        string $profileValue,
        string $mode,
        ?string $profileId = null,
        ?string $profileName = null
    ): array {
        return buildRechargePreview(
            $pdo,
            $device,
            $username,
            $profileValue,
            $mode,
            $profileId,
            $profileName
        );
    }
}
