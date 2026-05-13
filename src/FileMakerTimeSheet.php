<?php

namespace TimesheetEngine;

use RuntimeException;

/**
 * FileMakerTimeSheet
 *
 * Drop-in replacement for TimesheetEngine\TimeSheet.
 * Writes directly to FileMaker Server via the Data API
 * using the Timesheet layout + ItemsTimesheet portal.
 *
 * Flow:
 *   writeHeader() — finds or creates the Timesheet record for employee + week
 *   writeEntry()  — buffers portal rows (Items:: fields)
 *   save()        — PATCHes all rows in one call, releases session
 */
class FileMakerTimeSheet
{
    private string $baseUrl;
    private string $database;
    private string $username;
    private string $password;
    private string $layoutTimesheet = 'Timesheet';
    private array  $projects        = [];
    private array  $disciplines     = [];
    private array  $classifications = [];
    private array  $projectCode     = [];
    private array  $projectName     = [];  // display name without code prefix
    private array  $disciplineMap   = [
        'Developer'          => 'Developer',
        'Engineering'        => 'Engineering',
        'Network engineer'   => 'Network engineer',
        'Network'            => 'Network engineer',
        'Programmer'         => 'Programmer',
        'Project management' => 'Project management',
        'Project manager'    => 'Project management',
        'Sales engineer'     => 'Sales engineer',
        'Sales'              => 'Sales engineer',
        'Sales manager'      => 'Sales manager',
        'Senior technician'  => 'Senior technician',
        'Technician'         => 'Technician',
        'Other'              => 'Other',
        'Travel'             => null,
    ];

    private string $employeeName    = '';
    private string $employeeId      = '';
    private int    $weekNo          = 0;
    private int    $year            = 0;
    private ?int   $timesheetId     = null; // recordId of the Timesheet record for this week
    private array  $pendingRows     = [];   // buffered Items:: portal rows
    private string $token           = '';
    private bool   $dryRun          = false;
    private bool   $submit          = false;
    private string $projectManager  = '';
    private array  $classTypeIds    = [];  // ClassType -> ClassTypeID (recordId)
    private array  $classTypeRates  = [];  // ClassType -> Rate

    public function __construct(
        string $url,
        string $database,
        string $username,
        string $password,
        bool   $dryRun         = false,
        string $employeeId     = '',
        bool   $submit         = false,
        string $projectManager = ''
    ) {
        $this->baseUrl        = rtrim($url, '/');
        $this->database       = $database;
        $this->username       = $username;
        $this->password       = $password;
        $this->dryRun         = $dryRun;
        $this->employeeId     = $employeeId;
        $this->submit         = $submit;
        $this->projectManager = $projectManager;
        $this->token          = $this->authenticate();
        $this->loadValueLists();
    }

    // -------------------------------------------------------------------------
    // PUBLIC INTERFACE
    // -------------------------------------------------------------------------

    public function getProjects(): array        { return $this->projects; }
    public function getDisciplines(): array     { return $this->disciplines; }
    public function getClassifications(): array { return $this->classifications; }
    public function getPendingRows(): array     { return $this->pendingRows; }

    /**
     * Find or create the Timesheet record for this employee + week.
     */
    public function writeHeader(string $name, string $week, string $year): void
    {
        $this->employeeName = $name;
        $this->weekNo       = (int) $week;
        $this->year         = (int) $year;

        if (!$this->dryRun) {
            // Re-authenticate to get a fresh token before any write operations
            $this->logoutInternal();
            $this->token      = $this->authenticate();
            $this->timesheetId = $this->findOrCreateTimesheet($name, (int) $week);
        }
    }

    /**
     * Buffer one TimeEntry as an ItemsTimesheet portal row.
     */
    public function writeEntry(TimeEntry $entry, bool $withBillable = true): void
    {
        $row = [];

        if ($entry->date !== null) {
            $row['Items::Date'] = $entry->date->format('m/d/Y');
        }

        $this->resolveProject($entry->project, $row);

        if ($entry->workhours !== null && $entry->workhours > 0) {
            $row['Items::WorkingTime']   = $entry->workhours;
            $row['Items::TimeInstaller'] = $entry->workhours;
        }
        if ($entry->travelhours !== null && $entry->travelhours > 0) {
            $row['Items::TravelTime'] = $entry->travelhours;
        }
        if ($entry->traveldistance !== null && $entry->traveldistance > 0) {
            $row['Items::Kilometers'] = $entry->traveldistance;
        }
        if ($entry->parking !== null && $entry->parking > 0) {
            $row['Items::Parking'] = $entry->parking;
        }

        // Mark billable entries for invoicing — mirrors Excel engine behaviour.
        // The billable flag is set by process.php from the event description parameter
        // or derived from whether the project name contains 'indirect'.
        if ($withBillable && ($entry->billable ?? false)) {
            $row['Items::ToInvoice'] = 'y';
        }

        $classType = $this->resolveClassType($entry->discipline);
        if ($classType !== null) {
            $row['Items::ClassType'] = $classType;
        }

        // Store activity separately — it's written via Items Detail layout after the portal row is created
        if ($entry->activity !== null && $entry->activity !== '') {
            $row['activity'] = $entry->activity;
        }

        $this->pendingRows[] = $row;
    }

    /**
     * Write all buffered rows as portal entries on the Timesheet record.
     */
    public function save(string $file = ''): void
    {
        try {
            if (empty($this->pendingRows)) {
                echo "[FM] No entries to submit.\n";
                return;
            }

            if ($this->weekNo === 0 || $this->employeeName === '') {
                throw new RuntimeException('[FM] writeHeader() must be called before save().');
            }

            if ($this->dryRun) {
                echo "[FM] DRY RUN — would submit " . count($this->pendingRows)
                    . " portal rows for {$this->employeeName} week {$this->weekNo}/{$this->year}:\n";
                echo json_encode($this->pendingRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                return;
            }

            if ($this->timesheetId === null) {
                throw new RuntimeException('[FM] No Timesheet record — writeHeader() failed?');
            }

            // Re-authenticate to ensure we have a fresh token — the iCal
            // processing phase can take long enough to expire the session
            $this->logoutInternal();
            $this->token = $this->authenticate();

            $url = $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}/records/{$this->timesheetId}");

            foreach ($this->pendingRows as $row) {
                $activity = $row['activity'] ?? null;
                unset($row['activity']);

                if ($activity !== null) {
                    $row['Items::Activity'] = $activity;
                }

                $response = $this->request('PATCH', $url, [
                    'fieldData'  => new \stdClass(),
                    'portalData' => ['ItemsTimesheet' => [$row]],
                ], [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                ]);

                if ($itemsRecordId !== null) {
                    $classType   = $row['Items::ClassType'] ?? null;
                    $classTypeId = $classType ? ($this->classTypeIds[$classType] ?? null) : null;
                    $rate        = $classType ? ($this->classTypeRates[$classType] ?? null) : null;

                    $itemsFields = array_filter([
                        'Employee'    => $this->employeeName,
                        'EmployeeID'  => $this->employeeId ?: null,
                        'Year'        => $this->year ?: null,
                        'weekno'      => $this->weekNo ?: null,
                        'Rate'        => $rate,
                        'ClassTypeID' => $classTypeId,
                    ], fn($v) => $v !== null && $v !== '');

                    if (!empty($itemsFields)) {
                        $this->request('PATCH',
                            $this->apiUrl("databases/{$this->database}/layouts/Items%20Detail/records/{$itemsRecordId}"),
                            ['fieldData' => $itemsFields],
                            [
                                'Authorization: Bearer ' . $this->token,
                                'Content-Type: application/json',
                            ]
                        );
                    }
                }
            }

            echo "[FM] Submitted " . count($this->pendingRows)
                . " entries for {$this->employeeName} week {$this->weekNo}/{$this->year}.\n";

            if ($this->submit) {
                // Set Date submitted and project manager on the Timesheet record
                $this->request('PATCH',
                    $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}/records/{$this->timesheetId}"),
                    ['fieldData' => [
                        'Date submitted employee' => date('m/d/Y'),
                        'ProjectManager'          => $this->projectManager,
                    ]],
                    ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json']
                );
                echo "[FM] Timesheet submitted — date set to " . date('d-m-Y') . ", project manager set to {$this->projectManager}.\n";
            }

        } finally {
            $this->logoutInternal();
        }
    }

    // -------------------------------------------------------------------------
    // TIMESHEET RECORD
    // -------------------------------------------------------------------------

    private function findOrCreateTimesheet(string $employeeName, int $weekNo): int
    {
        // Try to find existing record
        $response = $this->request(
            'POST',
            $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}/_find"),
            ['query' => [['Employee' => $employeeName, 'weekno' => (string) $weekNo]]],
            ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json']
        );

        $code = $response['messages'][0]['code'] ?? '-1';

        if ($code === '0') {
            $recordId = (int) $response['response']['data'][0]['recordId'];
            echo "[FM] Found Timesheet record {$recordId} for {$employeeName} week {$weekNo} — clearing existing entries.\n";

            $deleted = 0;
            do {
                $full = $this->request('GET',
                    $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}/records/{$recordId}"),
                    null,
                    ['Authorization: Bearer ' . $this->token]
                );
                $existing = $full['response']['data'][0]['portalData']['ItemsTimesheet'] ?? [];

                foreach ($existing as $row) {
                    $this->request('DELETE',
                        $this->apiUrl("databases/{$this->database}/layouts/Items/records/{$row['recordId']}"),
                        null,
                        ['Authorization: Bearer ' . $this->token]
                    );
                    $deleted++;
                }
            } while (!empty($existing));

            if ($deleted > 0) {
                echo "[FM] Deleted {$deleted} existing entries.\n";
            }

            return $recordId;
        }

        if ($code === '401') {
            // Not found — create it
            $response = $this->request(
                'POST',
                $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}/records"),
                ['fieldData' => ['Employee' => $employeeName, 'weekno' => (string) $weekNo]],
                ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json']
            );

            if (($response['messages'][0]['code'] ?? '-1') !== '0') {
                throw new RuntimeException('[FM] Failed to create Timesheet record: ' . json_encode($response));
            }

            $recordId = (int) $response['response']['recordId'];
            echo "[FM] Created Timesheet record {$recordId} for {$employeeName} week {$weekNo}.\n";
            return $recordId;
        }

        throw new RuntimeException('[FM] Failed to find Timesheet record: ' . json_encode($response));
    }

    // -------------------------------------------------------------------------
    // FM SESSION
    // -------------------------------------------------------------------------

    private function authenticate(): string
    {
        $response = $this->request('POST',
            $this->apiUrl("databases/{$this->database}/sessions"),
            new \stdClass(),
            [
                'Authorization: Basic ' . base64_encode("{$this->username}:{$this->password}"),
                'Content-Type: application/json',
            ]
        );

        if (empty($response['response']['token'])) {
            throw new RuntimeException('[FM] Authentication failed: ' . json_encode($response));
        }

        echo "[FM] Authenticated.\n";
        return $response['response']['token'];
    }

    private function logoutInternal(): void
    {
        if ($this->token === '') return;
        $this->request('DELETE',
            $this->apiUrl("databases/{$this->database}/sessions/{$this->token}"),
            null,
            ['Authorization: Bearer ' . $this->token]
        );
        $this->token = '';
    }

    // -------------------------------------------------------------------------
    // VALUE LIST LOADING
    // -------------------------------------------------------------------------

    private function loadValueLists(): void
    {
        $response = $this->request('GET',
            $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}"),
            null,
            ['Authorization: Bearer ' . $this->token]
        );

        foreach ($response['response']['valueLists'] ?? [] as $vl) {
            match ($vl['name'] ?? '') {
                'Projecten' => $this->parseProjecten($vl['values']),
                'Uursoort'  => $this->parseUursoort($vl['values']),
                default     => null,
            };
        }

        // Load ClassTypeID and Rate from Classification layout
        $classResp = $this->request('GET',
            $this->apiUrl("databases/{$this->database}/layouts/Classification/records?_limit=50"),
            null,
            ['Authorization: Bearer ' . $this->token]
        );
        foreach ($classResp['response']['data'] ?? [] as $rec) {
            $classType = $rec['fieldData']['ClassType'] ?? null;
            if ($classType !== null) {
                $this->classTypeIds[$classType] = (int) $rec['recordId'];
                $this->classTypeRates[$classType] = (float) ($rec['fieldData']['Rate'] ?? 0);
            }
        }

        echo "[FM] Loaded " . count($this->projects) . " projects, " . count($this->disciplines) . " disciplines, " . count($this->classTypeIds) . " class types.\n";
    }

    private function parseProjecten(array $values): void
    {
        foreach ($values as $item) {
            $code    = $item['value'];
            $display = $item['displayValue'] ?? $code;

            // FM display values include the code as prefix (e.g. "P2526 Weteringschans 16 - Amsterdam - D")
            // Strip it so Projectname contains only the name, matching what the form stores
            $nameOnly = trim(preg_replace('/^' . preg_quote($code, '/') . '\s+/', '', $display));

            if (!in_array($display, $this->projects, true)) {
                $this->projects[]            = $display;   // keep full display for matching
                $this->projectCode[$display] = $code;
                $this->projectName[$display] = $nameOnly;  // name without code prefix
            }
        }
    }

    private function parseUursoort(array $values): void
    {
        foreach ($values as $item) {
            $val = $item['value'] ?? '';
            if ($val !== '' && !in_array($val, $this->disciplines, true)) {
                $this->disciplines[] = $val;
            }
        }
        $this->classifications = array_values(array_filter(
            $this->disciplines, fn($d) => !in_array($d, ['Travel', 'Other'], true)
        ));
    }

    // -------------------------------------------------------------------------
    // FIELD MAPPING
    // -------------------------------------------------------------------------

    private function resolveProject(?string $projectName, array &$row): void
    {
        if ($projectName === null) return;

        if (isset($this->projectCode[$projectName])) {
            $row['Items::Projectno']   = $this->projectCode[$projectName];
            $row['Items::Projectname'] = $this->projectName[$projectName] ?? $projectName;
            return;
        }

        // Strip unmatched suffix appended by process.php e.g. "Foo (D&Z Domotica / IT Specialist)"
        $stripped = preg_replace('/\s*\([^)]+\)\s*$/', '', $projectName);
        if ($stripped !== $projectName && isset($this->projectCode[$stripped])) {
            $row['Items::Projectno']   = $this->projectCode[$stripped];
            $row['Items::Projectname'] = $this->projectName[$stripped] ?? $stripped;
            return;
        }

        echo "[FM] WARNING: No FM project code for '$projectName'\n";
        $row['Items::Projectname'] = $projectName;
    }

    private function resolveClassType(?string $discipline): ?string
    {
        if ($discipline === null) return null;
        if (array_key_exists($discipline, $this->disciplineMap)) return $this->disciplineMap[$discipline];
        foreach ($this->disciplineMap as $key => $value) {
            if (strcasecmp($key, $discipline) === 0) return $value;
        }
        if (in_array($discipline, $this->disciplines, true)) return $discipline;
        echo "[FM] WARNING: No ClassType match for '$discipline', defaulting to 'Other'\n";
        return 'Other';
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function apiUrl(string $path): string
    {
        return "{$this->baseUrl}/fmi/data/v1/{$path}";
    }

    private function request(string $method, string $url, mixed $body, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new RuntimeException("[FM] cURL error: $error");
        $decoded = json_decode($raw, true);
        if ($decoded === null) throw new RuntimeException("[FM] Invalid JSON response (HTTP $httpCode): $raw");
        return $decoded;
    }
}
