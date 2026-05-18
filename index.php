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

// CONTROL TOGGLE: Set to true to view flags inside b24_dedup_test_log.json without deleting
define('DRY_RUN', true); 

// Filesystem Output Destinations
define('ACTIVITY_LOG_FILE', __DIR__ . '/dedup_activity.log');
define('JSON_PREVIEW_FILE', __DIR__ . '/b24_dedup_test_log.json');

// ==========================================================================
// 2. EXTRACTION LAYER (FIX FOR APPLICATION/JSON & GET HANDLERS)
// ==========================================================================
$entityIdFromWebhook = $_POST['data']['id'] 
                       ?? $_POST['data']['FIELDS']['ID'] 
                       ?? $_POST['id'] 
                       ?? $_GET['id'] 
                       ?? null;

// Fallback: Parse raw payload input stream if standard post arrays turn up empty
if (empty($entityIdFromWebhook)) {
    $rawInputStream = file_get_contents('php://input');
    if (!empty($rawInputStream)) {
        $parsedJson = json_decode($rawInputStream, true);
        $entityIdFromWebhook = $parsedJson['data']['id'] 
                               ?? $parsedJson['data']['FIELDS']['ID'] 
                               ?? $parsedJson['id'] 
                               ?? null;
    }
}

define('TARGET_SPA_ID', (int)$entityIdFromWebhook); 

// Safeguard: Stop execution if no valid record ID is found
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
writeLog("Execution Mode: " . (DRY_RUN ? "DRY-RUN MODE (Safe Flagging)" : "LIVE MODE (Destructive)"), DRY_RUN ? 'INFO' : 'WARNING');

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

// Safeguard: Ensure both verification criteria values exist on the parent record
if (empty($spaPhone) || empty($spaEmail)) {
    writeLog("Aborting Pipeline: Both Phone AND Email fields must be populated on the parent record to evaluate strict matches.", 'INFO');
    exit(0);
}

$hasPlusInSource = (strpos($spaPhone, '+') === 0);
$normSpaPhone = normalizePhone($spaPhone, $hasPlusInSource);
writeLog("Target Profile -> Phone: '$normSpaPhone' AND Email: '$spaEmail'");

// PHASE 1: Collect Candidate SPA items using a strict structural database filter
writeLog("Step 1: Querying database for records matching BOTH targets...");
$rawCandidatePool = [];

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

// PHASE 2: Deep Extraction & Re-Verification
writeLog("Step 2: Processing verification filtering on " . count($rawCandidatePool) . " items...");
$verifiedItems = [];

foreach ($rawCandidatePool as $id => $item) {
    // Automatically skip processing the item that triggered this run
    if ((int)$id === TARGET_SPA_ID) {
        continue;
    }

    $itemPhone = normalizePhone($item['PHONE'], $hasPlusInSource);
    $itemEmail = strtolower($item['EMAIL']);
    
    $hasPhoneMatch = (!empty($normSpaPhone) && $itemPhone === $normSpaPhone);
    $hasEmailMatch = (!empty($spaEmail) && $itemEmail === strtolower($spaEmail));
    
    // Strict comparison check: Both must evaluate to true
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
        writeLog("--> PASSED FLAG: SPA Item #$id matches both parameters.", 'SUCCESS');
    } else {
        writeLog("--> REJECTED: SPA Item #$id failed compound matching checks.", 'WARNING');
    }
}

// PHASE 3: Determine Duplication Conflict Arrays
$totalVerifiedCount = count($verifiedItems);
writeLog("Total background records verified as true duplicates: $totalVerifiedCount");

if ($totalVerifiedCount < 1) {
    writeLog("Clean finish: No concurrent duplicates found inside database topology.", 'SUCCESS');
    exit(0);
}

// Include the current incoming record into the pool to correctly sort historical data
$verifiedItems[TARGET_SPA_ID] = [
    'ID'          => (string)TARGET_SPA_ID,
    'TITLE'       => $spaItem['item']['title'] ?? 'Incoming SPA',
    'DATE_CREATE' => $spaItem['item']['dateCreate'] ?? date('c'),
    'EXTRACTED_DATA' => ['phone' => $spaPhone, 'email' => $spaEmail]
];

// Sort descending by ID value (Newest items populate at the top of the list)
uasort($verifiedItems, function($a, $b) {
    return (int)$b['ID'] - (int)$a['ID'];
});

$latestItem = array_shift($verifiedItems);
$itemsToDelete = $verifiedItems;

writeLog("RETAINED WINNER (Newest Record): ID #{$latestItem['ID']} - '{$latestItem['TITLE']}'", 'SUCCESS');
writeLog("FLAGGED FOR DISPOSAL (Older Records): " . count($itemsToDelete) . " items inside cluster.", 'WARNING');

// PHASE 4: Write Audit File & Delete Target Data Matrix
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'incoming_trigger_id' => TARGET_SPA_ID,
    'kept_item' => $latestItem,
    'flagged_for_deletion' => array_values($itemsToDelete)
];

file_put_contents(JSON_PREVIEW_FILE, json_encode($logData, JSON_PRETTY_PRINT));

if (!DRY_RUN) {
    writeLog("CRITICAL WARNING: Dry run disabled. Executing permanent deletion loops.", 'WARNING');
    foreach ($itemsToDelete as $item) {
        $realB24Id = $item['ID'];
        writeLog("Firing deletion on duplicate SPA Item ID #$realB24Id ('{$item['TITLE']}')");
        
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
            writeLog("Successfully removed duplicate SPA Item ID #$realB24Id.", 'SUCCESS');
        } else {
            $apiError = $rawResult['error_description'] ?? $rawResult['error'] ?? 'Unknown API block';
            writeLog("Failure on SPA Item ID #$realB24Id: " . $apiError, 'ERROR');
        }
    }
    writeLog("Data purge phase concluded.", 'SUCCESS');
} else {
    writeLog("[DRY RUN ACTIVE]: Operations simulated. Review your flagged targets inside: " . JSON_PREVIEW_FILE, 'INFO');
}

writeLog("SCRIPT EXECUTION COMPLETED.");
writeLog("==========================================================================");