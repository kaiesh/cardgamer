<?php

class ActionLogController {
    public static function getLog(string $tableId): array {
        return TableController::getActions($tableId);
    }
}
