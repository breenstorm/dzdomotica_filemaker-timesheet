<?php

namespace TimesheetEngine;

use RuntimeException;

/**
 * FileMakerTimeSheet
 *
 * Drop-in replacement for TimesheetEngine\TimeSheet.
 * Writes directly to FileMaker Server via the Data API
 * instead of generating an Excel file.
 *
 * Reuses TimesheetEngine\TimeEntry from dzdomotica_timesheet-engine,
 * which is declared as a composer dependency of this package.
 *
 * Same public interface as TimeSheet:
 *   getProjects(), getDisciplines(), getClassifications()
 *   writeHeader(), writeEntry(), save()
 *
 * In process.php, replace:
 *
 *   $timeSheet = new TimeSheet(__DIR__ . '/master.xlsx');
 *
 * With:
 *
 *   $timeSheet = new FileMakerTimeSheet(
 *       url:      $_ENV['FM_URL'],
 *       database: $_ENV['FM_DATABASE'],
 *       username: $_ENV['FM_USER'],
 *       password: $_ENV['FM_PASS']
 *   );
 *
 * Add to .env:
 *   FM_URL=https://filemaker.dzdomotica.com
 *   FM_DATABASE=Timesheet
 *   FM_USER=youruser
 *   FM_PASS=yourpassword
 *
 * save() ignores its $filename argument — it submits all buffered
 * entries to FileMaker and releases the session.
 */
class FileMakerTimeSheet
{
    private string $baseUrl;
    private string $database;
    private string $username;
    private string $password;
    private string $layout = 'Timesheet';

    // Lookup lists populated from FM value lists at construction,
    // returned by getProjects() / getDisciplines() / getClassifications()
    // so process.php's fuzzy matching works identically to the Excel version.
    private array $projects        = [];
    private array $disciplines     = [];
    private array $classifications = [];

    // Maps FM project display name → project code (e.g. 'P1526')
    private array $projectCode = [];

    // Maps TimeEntry->discipline values to FM ClassType (Uursoort) entries.
    // FM Uursoort values: Developer, Engineering, Network engineer, Other,
    //                     Programmer, Project management, Sales engineer,
    //                     Sales manager, Senior technician, Technician
    private array $disciplineMap = [
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
        'Travel'             => null, // travel lines → TravelTime, not ClassType
    ];

    // State set by writeHeader()
    private string $employeeName = '';
    private int    $weekNo       = 0;
    private int    $year         = 0;

    // Portal rows buffered by writeEntry(), flushed in save()
    private array $pendingRows = [];

    // FM session token, held from construction through save()
    private string $token = '';

    // -------------------------------------------------------------------------
    // CONSTRUCTION
    // -------------------------------------------------------------------------

    public function __construct(
        string $url,
        string $database,
        string $username,
        string $password
    ) {
        $this->baseUrl  = rtrim($url, '/');
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;

        $this->token = $this->authenticate();
        $this->loadValueLists();
    }

    // -------------------------------------------------------------------------
    // PUBLIC INTERFACE  (mirrors TimesheetEngine\TimeSheet)
    // -------------------------------------------------------------------------

    /**
     * Project display names from FM's Projecten value list,
     * e.g. "P1526 D&Z Domotica NL - D".
     * Fed into process.php's fuzzy project matcher.
     */
    public function getProjects(): array
    {
        return $this->projects;
    }

    /**
     * ClassType values from FM's Uursoort value list.
     * Fed into process.php's discipline matcher.
     */
    public function getDisciplines(): array
    {
        return $this->disciplines;
    }

    /**
     * Kept for interface compatibility with TimeSheet.
     * Returns the discipline list minus generic catch-alls.
     */
    public function getClassifications(): array
    {
        return $this->classifications;
    }

    /**
     * Store employee + week context for the FM parent record.
     * Called identically to TimeSheet::writeHeader().
     */
    public function writeHeader(string $name, string $week, string $year): void
    {
        $this->employeeName = $name;
        $this->weekNo       = (int) $week;
        $this->year         = (int) $year;
    }

    /**
     * Buffer one TimesheetEngine\TimeEntry as a FileMaker Items portal row.
     * Called identically to TimeSheet::writeEntry().
     *
     * TimeEntry is provided by the dzdomotica_timesheet-engine package
     * and reused here directly — no duplication.
     */
    public function writeEntry(TimeEntry $entry, bool $withBillable = true): void
    {
        $row = [];

        // Date — FM expects MM/DD/YYYY
        if ($entry->date !== null) {
            $row['Items::Date'] = $entry->date->format('m/d/Y');
        }

        // Project — resolve FM code from the display name process.php matched
        $this->resolveProject($entry->project, $row);

        // Hours
        if ($entry->workhours !== null && $entry->workhours > 0) {
            $row['Items::WorkingTime'] = $entry->workhours;
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

        // ClassType
        $classType = $this->resolveClassType($entry->discipline);
        if ($classType !== null) {
            $row['Items::ClassType'] = $classType;
        }

        // Billable / declare flags ('y' or '')
        if ($withBillable) {
            $row['Items::ToInvoice'] = $entry->billable ? 'y' : '';
        }

        $hasExpenses = ($entry->parking       !== null && $entry->parking       > 0)
                    || ($entry->traveldistance !== null && $entry->traveldistance > 0);
        $row['Items::ToDeclare'] = $hasExpenses ? 'y' : '';

        $this->pendingRows[] = $row;
    }

    /**
     * Submit all buffered entries to FileMaker and release the session.
     * The $filename argument is accepted for interface compatibility but ignored.
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

            $recordId = $this->findOrCreateWeekRecord();
            echo "[FM] Using week {$this->weekNo} record ID: $recordId\n";

            $this->writePortalRows($recordId);
            echo "[FM] Submitted " . count($this->pendingRows) . " entries"
                . " for {$this->employeeName} week {$this->weekNo}/{$this->year}.\n";

        } finally {
            $this->logout();
        }
    }

    // -------------------------------------------------------------------------
    // FM SESSION
    // -------------------------------------------------------------------------

    private function authenticate(): string
    {
        $url      = $this->apiUrl("databases/{$this->database}/sessions");
        $response = $this->request('POST', $url, [], [
            'Authorization: Basic ' . base64_encode("{$this->username}:{$this->password}"),
            'Content-Type: application/json',
        ]);

        if (empty($response['response']['token'])) {
            throw new RuntimeException('[FM] Authentication failed: ' . json_encode($response));
        }

        echo "[FM] Authenticated.\n";
        return $response['response']['token'];
    }

    private function logout(): void
    {
        if ($this->token === '') return;
        $url = $this->apiUrl("databases/{$this->database}/sessions/{$this->token}");
        $this->request('DELETE', $url, null, [
            'Authorization: Bearer ' . $this->token,
        ]);
        $this->token = '';
    }

    // -------------------------------------------------------------------------
    // VALUE LIST LOADING
    // -------------------------------------------------------------------------

    private function loadValueLists(): void
    {
        $url      = $this->apiUrl("databases/{$this->database}/layouts/{$this->layout}");
        $response = $this->request('GET', $url, null, [
            'Authorization: Bearer ' . $this->token,
        ]);

        foreach ($response['response']['valueLists'] ?? [] as $vl) {
            match ($vl['name'] ?? '') {
                'Projecten' => $this->parseProjecten($vl['values']),
                'Uursoort'  => $this->parseUursoort($vl['values']),
                default     => null,
            };
        }

        echo "[FM] Loaded " . count($this->projects)
            . " projects, " . count($this->disciplines) . " disciplines.\n";
    }

    private function parseProjecten(array $values): void
    {
        foreach ($values as $item) {
            $display = $item['displayValue'] ?? $item['value'];
            $code    = $item['value'];
            if (!in_array($display, $this->projects, true)) {
                $this->projects[]            = $display;
                $this->projectCode[$display] = $code;
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

        // Classifications: same list minus catch-alls, mirroring the Excel engine
        $this->classifications = array_values(array_filter(
            $this->disciplines,
            fn($d) => !in_array($d, ['Travel', 'Other'], true)
        ));
    }

    // -------------------------------------------------------------------------
    // RECORD OPERATIONS
    // -------------------------------------------------------------------------

    private function findOrCreateWeekRecord(): string
    {
        $url      = $this->apiUrl("databases/{$this->database}/layouts/{$this->layout}/_find");
        $response = $this->request('POST', $url, [
            'query' => [
                ['Employee' => $this->employeeName, 'weekno' => (string) $this->weekNo],
            ],
            'limit' => 1,
        ], [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ]);

        // FM returns error code 401 when a find yields no records
        if (($response['messages'][0]['code'] ?? '') === '401') {
            echo "[FM] No existing record for week {$this->weekNo}, creating one.\n";
            return $this->createWeekRecord();
        }

        if (!empty($response['response']['data'][0]['recordId'])) {
            return $response['response']['data'][0]['recordId'];
        }

        throw new RuntimeException('[FM] Unexpected find response: ' . json_encode($response));
    }

    private function createWeekRecord(): string
    {
        $url      = $this->apiUrl("databases/{$this->database}/layouts/{$this->layout}/records");
        $response = $this->request('POST', $url, [
            'fieldData' => [
                'Employee' => $this->employeeName,
                'weekno'   => $this->weekNo,
            ],
        ], [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ]);

        if (empty($response['response']['recordId'])) {
            throw new RuntimeException('[FM] Failed to create week record: ' . json_encode($response));
        }

        return $response['response']['recordId'];
    }

    private function writePortalRows(string $recordId): void
    {
        $url      = $this->apiUrl("databases/{$this->database}/layouts/{$this->layout}/records/$recordId");
        $response = $this->request('PATCH', $url, [
            'fieldData'  => new \stdClass(), // FM requires this key even when empty
            'portalData' => [
                'ItemsTimesheet' => $this->pendingRows,
            ],
        ], [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ]);

        if (($response['messages'][0]['code'] ?? '-1') !== '0') {
            throw new RuntimeException('[FM] Failed to write portal rows: ' . json_encode($response));
        }
    }

    // -------------------------------------------------------------------------
    // FIELD MAPPING
    // -------------------------------------------------------------------------

    /**
     * Resolve the FM project code from a display name and populate
     * Items::Projectno + Items::Projectname in the portal row.
     *
     * process.php already did fuzzy matching against getProjects() and
     * stored the winning display name in $entry->project, so a direct
     * lookup in $projectCode is usually sufficient. The fallback handles
     * the case where matching failed and process.php appended the raw
     * descriptor in parentheses.
     */
    private function resolveProject(?string $projectName, array &$row): void
    {
        if ($projectName === null) return;

        // Direct hit — the name came from our own value list
        if (isset($this->projectCode[$projectName])) {
            $row['Items::Projectno']   = $this->projectCode[$projectName];
            $row['Items::Projectname'] = $projectName;
            return;
        }

        // process.php appends " (rawDesc)" on unmatched projects — strip and retry
        $stripped = preg_replace('/\s*\([^)]+\)\s*$/', '', $projectName);
        if ($stripped !== $projectName && isset($this->projectCode[$stripped])) {
            $row['Items::Projectno']   = $this->projectCode[$stripped];
            $row['Items::Projectname'] = $stripped;
            return;
        }

        echo "[FM] WARNING: No FM project code for '$projectName'\n";
        $row['Items::Projectname'] = $projectName;
    }

    /**
     * Map a TimeEntry discipline string to the FM ClassType value.
     * Returns null for Travel entries (they carry no ClassType).
     */
    private function resolveClassType(?string $discipline): ?string
    {
        if ($discipline === null) return null;

        // Exact map
        if (array_key_exists($discipline, $this->disciplineMap)) {
            return $this->disciplineMap[$discipline];
        }

        // Case-insensitive fallback
        foreach ($this->disciplineMap as $key => $value) {
            if (strcasecmp($key, $discipline) === 0) {
                return $value;
            }
        }

        // If the live FM Uursoort list contains it verbatim, trust it
        if (in_array($discipline, $this->disciplines, true)) {
            return $discipline;
        }

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
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("[FM] cURL error: $error");
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            throw new RuntimeException("[FM] Invalid JSON response (HTTP $httpCode): $raw");
        }

        return $decoded;
    }
}
