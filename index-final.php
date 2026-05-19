<?php
/**
 * Bitrix24 Bulk SPA Deduplication & Flagging Engine
 * File Name: index.php
 * https://myemirateshome.com/jenkins-automation/delete-old-lead-spa/index.php?dry_run=false
 * https://myemirateshome.com/jenkins-automation/delete-old-lead-spa/index.php?dry_run=true
 * 
 * // By default dry_run is true
 * https://myemirateshome.com/jenkins-automation/delete-old-lead-spa/index.php 
*/

// ==========================================================================
// 1. GLOBAL SYSTEM CONFIGURATION
// ==========================================================================
define('B24_WEBHOOK_URL', 'https://b24-sgn7y5.bitrix24.in/rest/14/kdho27qenzo9pv03/'); 
define('SPA_ENTITY_TYPE_ID', 1038); 

// Field Mapping Definitions
define('SPA_PHONE_FIELD', 'ufCrm8Phone'); 
define('SPA_EMAIL_FIELD', 'ufCrm8Email'); 
define('MATCHING_RULE', 'same_last_10_phone_digits');

// Code-level default. Runtime override examples:
// URL: index.php?dry_run=true   or   index.php?dry_run=false
// CLI: php index.php dry_run=true   or   php index.php dry_run=false
define('DEFAULT_DRY_RUN', true);
$dryRunConfig = resolveDryRunFlag(DEFAULT_DRY_RUN);
define('DRY_RUN', $dryRunConfig['value']);
define('DRY_RUN_SOURCE', $dryRunConfig['source']);

// Filesystem Output Destinations
define('BULK_LOG_FILE', __DIR__ . '/bulk_dedup_activity.log');
define('JSON_PREVIEW_FILE', __DIR__ . '/b24_dedup_test_log.json');

set_time_limit(0); // Prevent script timeout for large databases

// ==========================================================================
// 2. CORE UTILITY INFRASTRUCTURE
// ==========================================================================

function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    echo $formattedMessage;
    file_put_contents(BULK_LOG_FILE, $formattedMessage, FILE_APPEND);
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
    return $decoded;
}

function resolveDryRunFlag($defaultValue) {
    $acceptedKeys = ['dry_run', 'dryrun', 'DRY_RUN'];
    $rawValue = null;
    $source = 'code default';

    foreach ($acceptedKeys as $key) {
        if (isset($_GET[$key])) {
            $rawValue = $_GET[$key];
            $source = "URL parameter '$key'";
            break;
        }

        if (isset($_POST[$key])) {
            $rawValue = $_POST[$key];
            $source = "POST parameter '$key'";
            break;
        }
    }

    if ($rawValue === null && PHP_SAPI === 'cli' && !empty($_SERVER['argv'])) {
        foreach (array_slice($_SERVER['argv'], 1) as $arg) {
            if (strpos($arg, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $arg, 2);
            if (in_array($key, $acceptedKeys, true)) {
                $rawValue = $value;
                $source = "CLI argument '$key'";
                break;
            }
        }
    }

    if ($rawValue === null || $rawValue === '') {
        return [
            'value' => (bool)$defaultValue,
            'source' => $source
        ];
    }

    $parsedValue = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsedValue === null) {
        return [
            'value' => (bool)$defaultValue,
            'source' => "$source ignored invalid value '$rawValue'; using code default"
        ];
    }

    return [
        'value' => $parsedValue,
        'source' => "$source value '$rawValue'"
    ];
}

function normalizePhone($phone) {
    // Keep a leading '+' when present and keep every digit so country codes remain part of the comparison.
    $phone = trim((string)$phone);
    $normalized = preg_replace('/[^0-9]/', '', $phone);

    if (preg_match('/^\D*\+/', $phone) && $normalized !== '') {
        return '+' . $normalized;
    }

    return $normalized;
}

function getPhoneMatchKey($phone) {
    $digitsOnly = preg_replace('/[^0-9]/', '', normalizePhone($phone));

    if (strlen($digitsOnly) > 10) {
        return substr($digitsOnly, -10);
    }

    return $digitsOnly;
}

function normalizeEmail($email) {
    return strtolower(trim((string)$email));
}

function getMatchedBy($source, $candidate) {
    $matchedBy = [];

    if (
        !empty($source['NORM_PHONE']) &&
        !empty($candidate['NORM_PHONE']) &&
        !empty($source['PHONE_MATCH_KEY']) &&
        !empty($candidate['PHONE_MATCH_KEY']) &&
        $source['PHONE_MATCH_KEY'] === $candidate['PHONE_MATCH_KEY']
    ) {
        $matchedBy[] = 'phone';
    }

    return $matchedBy;
}

function isPhoneDuplicateMatch($source, $candidate) {
    $matchedBy = getMatchedBy($source, $candidate);
    return in_array('phone', $matchedBy, true);
}

function getRecordSortTimestamp($record) {
    if (!empty($record['DATE_CREATE'])) {
        $timestamp = strtotime($record['DATE_CREATE']);
        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    return 0;
}

function compareNewestRecords($a, $b) {
    $aTime = getRecordSortTimestamp($a);
    $bTime = getRecordSortTimestamp($b);

    if ($aTime !== $bTime) {
        return $bTime <=> $aTime;
    }

    return (int)$b['ID'] <=> (int)$a['ID'];
}

function previewRecord($record, $matchedBy = []) {
    $preview = [
        'ID'              => $record['ID'],
        'TITLE'           => $record['TITLE'],
        'DATE_CREATE'     => $record['DATE_CREATE'],
        'PHONE'           => $record['PHONE'],
        'NORMALIZED_PHONE' => $record['NORM_PHONE'] ?? normalizePhone($record['PHONE']),
        'PHONE_MATCH_KEY' => $record['PHONE_MATCH_KEY'] ?? getPhoneMatchKey($record['PHONE']),
        'EMAIL'           => $record['EMAIL']
    ];

    if (!empty($matchedBy)) {
        $preview['matched_by'] = $matchedBy;
    }

    return $preview;
}

// ==========================================================================
// 3. MAIN EXECUTION PIPELINE
// ==========================================================================

writeLog("==========================================================================");
writeLog("STARTING BULK SCAN: Fetching records for SPA Entity " . SPA_ENTITY_TYPE_ID . " (Stage: Start)");
writeLog("Mode: " . (DRY_RUN ? "DRY-RUN (Flagging & Mapping)" : "LIVE DELETION") . " via " . DRY_RUN_SOURCE, DRY_RUN ? 'INFO' : 'WARNING');
writeLog("Matching rule: " . MATCHING_RULE);

$allRecords = [];
$startRow = 0;

// Step 1: Batch-fetch records locked only to the 'Start' stage (handling Bitrix24's 50-item limit)
do {
    writeLog("Fetching batch starting at row offset: $startRow...");
    $response = callB24('crm.item.list', [
        'entityTypeId' => SPA_ENTITY_TYPE_ID,
        'select'       => ['ID', 'TITLE', 'DATE_CREATE', SPA_PHONE_FIELD, SPA_EMAIL_FIELD],
        'filter'       => [
            '=stageId' => 'DT1038_14:NEW' // Exact Stage ID confirmed via crm.status.list
        ],
        'start'        => $startRow
    ]);

    if (!isset($response['result']['items']) || !is_array($response['result']['items'])) {
        writeLog("Failed to fetch data or reached the end of records.", 'ERROR');
        break;
    }

    foreach ($response['result']['items'] as $item) {
        $phone = trim((string)($item[SPA_PHONE_FIELD] ?? ''));
        $email = strtolower(trim((string)($item[SPA_EMAIL_FIELD] ?? '')));

        if (!empty($phone)) {
            $allRecords[] = [
                'ID'              => (int)$item['id'],
                'TITLE'           => $item['title'] ?? 'Untitled SPA',
                'DATE_CREATE'     => $item['dateCreate'] ?? '',
                'PHONE'           => $phone,
                'NORM_PHONE'      => normalizePhone($phone),
                'PHONE_MATCH_KEY' => getPhoneMatchKey($phone),
                'EMAIL'           => normalizeEmail($email)
            ];
        }
    }

    $startRow = $response['next'] ?? null;
} while ($startRow !== null);

writeLog("Total valid records with usable Phone fetched in 'Start' stage: " . count($allRecords));

// Step 2: Fully automated source-by-source matching across the filtered dataset.
$flaggedMatrix = [];
$deletionPool = [];
$processedIds = [];

foreach ($allRecords as $source) {
    if (isset($processedIds[$source['ID']])) {
        continue;
    }

    writeLog("Scanning source SPA item #{$source['ID']} against 'Start' dataset...");

    $cluster = [$source];
    $matchDetails = [
        $source['ID'] => []
    ];

    foreach ($allRecords as $candidate) {
        if ($candidate['ID'] === $source['ID'] || isset($processedIds[$candidate['ID']])) {
            continue;
        }

        if (isPhoneDuplicateMatch($source, $candidate)) {
            $matchedBy = getMatchedBy($source, $candidate);
            $cluster[] = $candidate;
            $matchDetails[$candidate['ID']] = $matchedBy;
        }
    }

    if (count($cluster) <= 1) {
        $processedIds[$source['ID']] = true;
        continue;
    }

    usort($cluster, 'compareNewestRecords');

    $winner = array_shift($cluster);
    $duplicates = $cluster;

    $flaggedMatrix[] = [
        'source_spa_item' => previewRecord($source),
        'matching_profile' => [
            'normalized_phone' => $source['NORM_PHONE'],
            'phone_match_key'  => $source['PHONE_MATCH_KEY'],
            'email'            => $source['EMAIL']
        ],
        'count' => count($duplicates) + 1,
        'kept_latest_item' => previewRecord($winner, $matchDetails[$winner['ID']] ?? []),
        'duplicates_flagged' => array_map(function($d) use ($matchDetails) {
            return previewRecord($d, $matchDetails[$d['ID']] ?? []);
        }, $duplicates)
    ];

    $processedIds[$source['ID']] = true;
    $processedIds[$winner['ID']] = true;

    foreach ($duplicates as $dup) {
        $processedIds[$dup['ID']] = true;
        $deletionPool[$dup['ID']] = $dup['ID'];
    }
}

$deletionPool = array_values($deletionPool);

// Step 4: Write full diagnostic blueprint to JSON log file
$outputData = [
    'scan_timestamp' => date('Y-m-d H:i:s'),
    'matching_rule' => MATCHING_RULE,
    'total_duplicate_groups_found' => count($flaggedMatrix),
    'total_items_slated_for_deletion' => count($deletionPool),
    'duplicate_groups' => $flaggedMatrix
];

file_put_contents(JSON_PREVIEW_FILE, json_encode($outputData, JSON_PRETTY_PRINT));
writeLog("Deduplication matrix mapped completely. Output written to: " . JSON_PREVIEW_FILE);
writeLog("Total duplicate records detected within 'Start' stage: " . count($deletionPool));

// Step 5: Live Destruction (Only triggers if DRY_RUN is false)
if (!DRY_RUN && count($deletionPool) > 0) {
    writeLog("DRY_RUN IS FALSE: Commencing bulk production database cleanup...", 'WARNING');
    
    foreach ($deletionPool as $deleteId) {
        writeLog("Deleting duplicate SPA item ID #$deleteId...");
        $delResponse = callB24('crm.item.delete', [
            'entityTypeId' => SPA_ENTITY_TYPE_ID,
            'id'           => $deleteId
        ]);

        if (isset($delResponse['result'])) {
            writeLog("Successfully removed duplicate ID #$deleteId.", 'SUCCESS');
        } else {
            $err = $delResponse['error_description'] ?? 'API Rejection';
            writeLog("Failed to delete ID #$deleteId: $err", 'ERROR');
        }
        usleep(100000); // 100ms throttle pause to prevent hitting Bitrix24 call volume ceilings
    }
    writeLog("Bulk database purge completed.", 'SUCCESS');
} else {
    writeLog("[DRY RUN ACTIVE]: No data was deleted. Safely inspect " . JSON_PREVIEW_FILE . " to see the full list of flagged duplicates.");
}

writeLog("BULK EXECUTION ENGINE FINISHED.");
writeLog("==========================================================================");