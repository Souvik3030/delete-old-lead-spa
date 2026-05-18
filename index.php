<?php
/**
 * Bitrix24 SPA-to-SPA Deduplication Engine (Strict AND Matching Logic)
 * File Name: index.php
 */

// ==========================================================================
// 1. GLOBAL SYSTEM CONFIGURATION
// ==========================================================================
define('B24_WEBHOOK_URL', 'https://b24-sgn7y5.bitrix24.in/rest/14/kdho27qenzo9pv03/');
define('SPA_ENTITY_TYPE_ID', 1038); 

// Field Mapping Definitions
define('SPA_PHONE_FIELD', 'ufCrm8Phone'); 
define('SPA_EMAIL_FIELD', 'ufCrm8Email'); 

// Execution Mode Safeguard Toggle (Set to false for live automatic deletions)
define('DRY_RUN', false); 

// Filesystem Output Destinations
define('ACTIVITY_LOG_FILE', __DIR__ . '/dedup_activity.log');
define('JSON_PREVIEW_FILE', __DIR__ . '/b24_dedup_test_log.json');

// Dynamic ID capture: Multi-tiered fallback catching SPA payloads and explicit URL parameters
$entityIdFromWebhook = $_POST['data']['id'] 
                       ?? $_POST['data']['FIELDS']['ID'] 
                       ?? $_POST['id'] 
                       ?? $_GET['id'] 
                       ?? null;
                       
define('TARGET_SPA_ID', (int)$entityIdFromWebhook); 

// ==========================================================================
// 2. AUTOMATION INTERRUPT SAFEGUARD
// ==========================================================================
if (TARGET_SPA_ID <= 0) {
    writeLog("Engine halted: No valid dynamic SPA ID received from Bitrix24 webhook event payload.", 'INFO');
    exit(0);
}

// ==========================================================================
// 3. CORE UTILITY INFRASTRUCTURE
// ==========================================================================

function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    echo $formattedMessage;
    file_put_contents(ACTIVITY_LOG_FILE, $formattedMessage, FILE_APPEND);
}

function callB24($method, $params = []) {
    $url = rtrim(B24_WEBHOOK_URL, '/') . '/' . $method . '.json';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $response = curl_exec($ch);
    // curl_close($ch);
    $decoded = json_decode($response, true);
    return $decoded['result'] ?? null;
}

function normalizePhone($phone, $keepPlus = false) {
    $phone = trim((string)$phone);
    if ($keepPlus && strpos($phone, '+') === 0) {
        return '+' . preg_replace('/[^0-9]/', '', $phone);
    }
    return preg_replace('/[^0-9]/', '', $phone);
}

// ==========================================================================
// 4. EXECUTION PIPELINE
// ==========================================================================

writeLog("==========================================================================");
writeLog("SCRIPT START: Initializing SPA-to-SPA Deduplication Engine (Strict AND Mode).");
writeLog("Target Dynamic SPA ID: " . TARGET_SPA_ID);
writeLog("Execution Mode: " . (DRY_RUN ? "DRY-RUN MODE (Safe)" : "LIVE MODE (Destructive)"), DRY_RUN ? 'INFO' : 'WARNING');

// Fetch the Context of the Incoming Source SPA Item
$spaItem = callB24('crm.item.get', [
    'entityTypeId' => SPA_ENTITY_TYPE_ID,
    'id'           => TARGET_SPA_ID
]);

if (!$spaItem || !isset($spaItem['item'])) {
    writeLog("Critical Failure: Source SPA Item ID " . TARGET_SPA_ID . " could not be found inside CRM.", 'CRITICAL');
    exit(1);
}

$spaPhone = trim((string)($spaItem['item'][SPA_PHONE_FIELD] ?? ''));
$spaEmail = trim((string)($spaItem['item'][SPA_EMAIL_FIELD] ?? ''));

// CRITICAL SAFEGUARD: Both fields must be populated in the incoming item to execute AND logic
if (empty($spaPhone) || empty($spaEmail)) {
    writeLog("Aborting Pipeline: Both Phone AND Email must be present on the incoming record to process strict matches.", 'INFO');
    exit(0);
}

// Evaluate structural prefix rule definitions
$hasPlusInSource = (strpos($spaPhone, '+') === 0);
writeLog("Format Check: Source phone prefix '+' " . ($hasPlusInSource ? "DETECTED." : "NOT detected."));

$normSpaPhone = normalizePhone($spaPhone, $hasPlusInSource);
writeLog("Target Match Profile -> Normalized Phone Target: '$normSpaPhone' AND Email Target: '$spaEmail'");

// PHASE 1: Collect Candidate SPA items matching criteria fields via Bitrix24 AND Engine
writeLog("Step 1: Querying database for strict matching SPA items...");
$rawCandidatePool = [];

// Strict 'AND' database logic assignment 
$filterAND = [
    'LOGIC' => 'AND',
    '=' . SPA_PHONE_FIELD => $spaPhone,
    '=' . SPA_EMAIL_FIELD => $spaEmail
];

$spaList = callB24('crm.item.list', [
    'entityTypeId' => SPA_ENTITY_TYPE_ID,
    'filter'       => $filterAND,
    'select'       => ['ID', 'TITLE', 'DATE_CREATE', SPA_PHONE_FIELD, SPA_EMAIL_FIELD]
]);

if (is_array($spaList) && isset($spaList['items'])) {
    foreach ($spaList['items'] as $item) {
        $rawCandidatePool[$item['id']] = [
            'ID'          => $item['id'],
            'TITLE'       => $item['title'] ?? 'SPA Item',
            'DATE_CREATE' => $item['dateCreate'] ?? '',
            'PHONE'       => trim((string)($item[SPA_PHONE_FIELD] ?? '')),
            'EMAIL'       => trim((string)($item[SPA_EMAIL_FIELD] ?? ''))
        ];
    }
}

// PHASE 2: Strict Field-Level Extraction and Format Verification
writeLog("Step 2: Beginning deep validation on " . count($rawCandidatePool) . " collected candidates...");
$verifiedItems = [];

foreach ($rawCandidatePool as $id => $item) {
    // Drop the triggering record out of the processing pool
    if ((int)$id === TARGET_SPA_ID) {
        continue;
    }

    $itemPhone = normalizePhone($item['PHONE'], $hasPlusInSource);
    $itemEmail = strtolower($item['EMAIL']);
    
    $hasPhoneMatch = (!empty($normSpaPhone) && $itemPhone === $normSpaPhone);
    $hasEmailMatch = (!empty($spaEmail) && $itemEmail === strtolower($spaEmail));
    
    // Changed from || (OR) to && (AND) for strict validation integrity
    if ($hasPhoneMatch && $hasEmailMatch) {
        $verifiedItems[$id] = [
            'ID'          => $item['ID'],
            'TITLE'       => $item['TITLE'],
            'DATE_CREATE' => $item['DATE_CREATE'],
            'EXTRACTED_DATA' => [
                'phone' => $item['PHONE'],
                'email' => $item['EMAIL']
            ]
        ];
        writeLog("--> PASSED: SPA Item #$id matches BOTH phone and email confirmation targets.", 'SUCCESS');
    } else {
        writeLog("--> REJECTED: SPA Item #$id failed compound verification. Saved from deletion.", 'WARNING');
    }
}

// PHASE 3: Chronological Sorting Logic
$totalVerifiedCount = count($verifiedItems);
writeLog("Total verified matching SPA items remaining after validation: $totalVerifiedCount");

if ($totalVerifiedCount <= 1) {
    writeLog("Clean execution completed. No duplicate records verified in the system.", 'SUCCESS');
    exit(0);
}

// Sort items by ID descending to isolate the newest entry
uasort($verifiedItems, function($a, $b) {
    return (int)$b['ID'] - (int)$a['ID'];
});

$latestItem = array_shift($verifiedItems);
$itemsToDelete = $verifiedItems;

writeLog("WINNER RECORD RETAINED: SPA Item ID #{$latestItem['ID']} - '{$latestItem['TITLE']}'", 'SUCCESS');
writeLog("DISPOSAL MATRIX CONSOLIDATED: " . count($itemsToDelete) . " duplicate items targeted.", 'WARNING');

// PHASE 4: Output Audit Logging & Factory Deletions
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'spa_source' => [
        'id' => TARGET_SPA_ID,
        'phone' => $spaPhone,
        'email' => $spaEmail
    ],
    'kept_item' => $latestItem,
    'flagged_for_deletion' => array_values($itemsToDelete)
];

file_put_contents(JSON_PREVIEW_FILE, json_encode($logData, JSON_PRETTY_PRINT));

if (!DRY_RUN) {
    writeLog("CRITICAL WARNING: Dry run bypass confirmed. Entering live system data destruction phase.", 'WARNING');
    foreach ($itemsToDelete as $item) {
        $realB24Id = $item['ID'];
        writeLog("Attempting factory destruction call on SPA Item ID #$realB24Id ('{$item['TITLE']}')");
        
        $url = rtrim(B24_WEBHOOK_URL, '/') . '/crm.item.delete.json';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'entityTypeId' => SPA_ENTITY_TYPE_ID,
                'id'           => $realB24Id
            ]),
        ]);
        $response = curl_exec($ch);
        // curl_close($ch);
        
        $rawResult = json_decode($response, true);
        
        if (isset($rawResult['result'])) {
            writeLog("Successfully and PERMANENTLY deleted duplicate SPA Item ID #$realB24Id from Bitrix24.", 'SUCCESS');
        } else {
            $apiError = $rawResult['error_description'] ?? $rawResult['error'] ?? 'Unknown factory rejection';
            writeLog("System error encountered on SPA Item ID #$realB24Id: " . $apiError, 'ERROR');
            writeLog("Raw Server Response Payload: " . $response, 'DEBUG');
        }
    }
    writeLog("Live data purge execution pipeline concluded.", 'SUCCESS');
} else {
    writeLog("[DRY RUN ACTIVE]: Operations simulated. Review output data metrics inside file: " . JSON_PREVIEW_FILE, 'INFO');
}

writeLog("SCRIPT EXECUTION COMPLETED SUCCESSFULLY.");
writeLog("==========================================================================");