<?php
/**
 * API Router - dispatches requests to controllers.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/pusher.php';
require_once __DIR__ . '/../../src/controllers/AuthController.php';
require_once __DIR__ . '/../../src/controllers/LobbyController.php';
require_once __DIR__ . '/../../src/controllers/TableController.php';
require_once __DIR__ . '/../../src/controllers/ZoneController.php';
require_once __DIR__ . '/../../src/controllers/CardController.php';
require_once __DIR__ . '/../../src/controllers/ChipController.php';
require_once __DIR__ . '/../../src/controllers/ChatController.php';
require_once __DIR__ . '/../../src/controllers/TemplateController.php';
require_once __DIR__ . '/../../src/controllers/ActionLogController.php';
require_once __DIR__ . '/../../src/controllers/AdminController.php';
require_once __DIR__ . '/../../src/controllers/PusherAuthController.php';

// Initialize session
initSession();

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$uri = preg_replace('#^api/v1/#', '', $uri);
// Also strip just 'api/' prefix if v1 not present
$uri = preg_replace('#^api/#', '', $uri);
$segments = explode('/', $uri);

// CSRF check for non-GET requests (skip for Pusher auth which uses POST form data)
$isPusherAuth = ($segments[0] ?? '') === 'pusher' && ($segments[1] ?? '') === 'auth';
if ($method !== 'GET' && !$isPusherAuth) {
    validateCsrf();
}

header('Content-Type: application/json');

try {
    $result = routeRequest($method, $segments);
    jsonResponse($result);
} catch (\PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    errorResponse('Internal server error', 'DB_ERROR', 500);
} catch (\Exception $e) {
    error_log("Error: " . $e->getMessage());
    errorResponse('Internal server error', 'SERVER_ERROR', 500);
}

function routeRequest(string $method, array $segments): array {
    $s = function(int $i) use ($segments): ?string {
        return $segments[$i] ?? null;
    };

    // AUTH routes
    if ($s(0) === 'auth') {
        return match (true) {
            $method === 'POST' && $s(1) === 'request-otp' => AuthController::requestOtp(),
            $method === 'POST' && $s(1) === 'verify-otp'  => AuthController::verifyOtp(),
            $method === 'POST' && $s(1) === 'logout'      => AuthController::logout(),
            $method === 'GET'  && $s(1) === 'me'           => AuthController::me(),
            $method === 'PUT'  && $s(1) === 'me'           => AuthController::updateMe(),
            default => errorResponse('Not found', 'NOT_FOUND', 404),
        };
    }

    // LOBBY routes
    if ($s(0) === 'lobby') {
        return match (true) {
            $method === 'GET' && $s(1) === 'tables' && $s(2) !== null => LobbyController::getTable($s(2)),
            $method === 'GET' && $s(1) === 'tables'                   => LobbyController::listTables(),
            $method === 'GET' && $s(1) === 'online-users'              => LobbyController::onlineUsers(),
            default => errorResponse('Not found', 'NOT_FOUND', 404),
        };
    }

    // PUSHER auth
    if ($s(0) === 'pusher' && $s(1) === 'auth' && $method === 'POST') {
        PusherAuthController::auth();
    }

    // TEMPLATES routes (non-table)
    if ($s(0) === 'templates') {
        return match (true) {
            $method === 'GET'    && $s(1) === null         => TemplateController::list(),
            $method === 'GET'    && $s(1) !== null         => TemplateController::get((int)$s(1)),
            $method === 'POST'   && $s(1) === null         => TemplateController::create(),
            $method === 'PUT'    && $s(1) !== null         => TemplateController::update((int)$s(1)),
            $method === 'DELETE' && $s(1) !== null         => TemplateController::delete((int)$s(1)),
            default => errorResponse('Not found', 'NOT_FOUND', 404),
        };
    }

    // ADMIN routes
    if ($s(0) === 'admin') {
        return match (true) {
            $method === 'GET'    && $s(1) === 'stats'                          => AdminController::stats(),
            $method === 'GET'    && $s(1) === 'users'                          => AdminController::listUsers(),
            $method === 'GET'    && $s(1) === 'tables'                         => AdminController::listAllTables(),
            $method === 'GET'    && $s(1) === 'deck-skins'                     => AdminController::listDeckSkins(),
            $method === 'POST'   && $s(1) === 'deck-skins'                     => AdminController::uploadDeckSkin(),
            $method === 'PUT'    && $s(1) === 'deck-skins' && $s(2) !== null   => AdminController::updateDeckSkin((int)$s(2)),
            $method === 'DELETE' && $s(1) === 'deck-skins' && $s(2) !== null   => AdminController::deleteDeckSkin((int)$s(2)),
            $method === 'GET'    && $s(1) === 'table-logos'                    => AdminController::listTableLogos(),
            $method === 'POST'   && $s(1) === 'table-logos'                    => AdminController::uploadTableLogo(),
            $method === 'PUT'    && $s(1) === 'table-logos' && $s(2) !== null   => AdminController::updateTableLogo((int)$s(2)),
            $method === 'DELETE' && $s(1) === 'table-logos' && $s(2) !== null   => AdminController::deleteTableLogo((int)$s(2)),
            $method === 'GET'    && $s(1) === 'chat-phrases'                   => AdminController::listDefaultPhrases(),
            $method === 'POST'   && $s(1) === 'chat-phrases'                   => AdminController::addDefaultPhrase(),
            $method === 'DELETE' && $s(1) === 'chat-phrases' && $s(2) !== null => AdminController::deleteDefaultPhrase((int)$s(2)),
            default => errorResponse('Not found', 'NOT_FOUND', 404),
        };
    }

    // TABLE routes: /tables/{id}/...
    if ($s(0) === 'tables') {
        $tableId = $s(1);

        // POST /tables (create)
        if ($method === 'POST' && $tableId === null) {
            return TableController::create();
        }

        if ($tableId === null) {
            errorResponse('Not found', 'NOT_FOUND', 404);
        }

        // Table sub-routes
        $sub = $s(2);

        return match (true) {
            // Table lifecycle
            $method === 'POST' && $sub === 'join'            => TableController::join($tableId),
            $method === 'POST' && $sub === 'leave'           => TableController::leave($tableId),
            $method === 'POST' && $sub === 'kick'            => TableController::kick($tableId),
            $method === 'POST' && $sub === 'start'           => TableController::start($tableId),
            $method === 'POST' && $sub === 'pause'           => TableController::pause($tableId),
            $method === 'POST' && $sub === 'resume'          => TableController::resume($tableId),
            $method === 'POST' && $sub === 'close'           => TableController::close($tableId),
            $method === 'GET'  && $sub === 'state'           => TableController::getState($tableId),
            $method === 'GET'  && $sub === 'actions'         => TableController::getActions($tableId),
            $method === 'POST' && $sub === 'apply-template'  => TemplateController::applyToTable($tableId),

            // Zones
            $method === 'GET'    && $sub === 'zones' && $s(3) === null => ZoneController::list($tableId),
            $method === 'POST'   && $sub === 'zones' && $s(3) === null => ZoneController::create($tableId),
            $method === 'PUT'    && $sub === 'zones' && $s(3) !== null => ZoneController::update($tableId, (int)$s(3)),
            $method === 'DELETE' && $sub === 'zones' && $s(3) !== null => ZoneController::delete($tableId, (int)$s(3)),

            // Cards
            $method === 'POST' && $sub === 'cards' && $s(3) === 'action' => CardController::handleAction($tableId),

            // Chips
            $method === 'POST' && $sub === 'chips' && $s(3) === 'transfer'   => ChipController::transfer($tableId),
            $method === 'POST' && $sub === 'chips' && $s(3) === 'create-pot' => ChipController::createPot($tableId),
            $method === 'GET'  && $sub === 'chips'                            => ChipController::getChips($tableId),

            // Chat
            $method === 'POST'   && $sub === 'chat' && $s(3) === null                   => ChatController::send($tableId),
            $method === 'GET'    && $sub === 'chat' && $s(3) === null                    => ChatController::getMessages($tableId),
            $method === 'GET'    && $sub === 'chat' && $s(3) === 'phrases'               => ChatController::getPhrases($tableId),
            $method === 'POST'   && $sub === 'chat' && $s(3) === 'phrases'               => ChatController::addPhrase($tableId),
            $method === 'DELETE' && $sub === 'chat' && $s(3) === 'phrases' && $s(4) !== null => ChatController::deletePhrase($tableId, (int)$s(4)),

            default => errorResponse('Not found', 'NOT_FOUND', 404),
        };
    }

    errorResponse('Not found', 'NOT_FOUND', 404);
}
