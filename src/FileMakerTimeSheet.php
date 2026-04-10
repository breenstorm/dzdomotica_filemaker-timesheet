<?php
namespace TimesheetEngine;

use RuntimeException;

class FileMakerTimeSheet
{
    private string $baseUrl;
    private string $database;
    private string $username;
    private string $password;
    private string $layoutTimesheet = 'Timesheet';
    private string $layoutJob = 'Job';
    private array $projects        = [];
    private array $disciplines     = [];
    private array $classifications = [];
    private array $projectCode = [];
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
        'Travel'             => null,
    ];
    private string $employeeName = '';
    private int    $weekNo       = 0;
    private int    $year         = 0;
    private array  $pendingRows  = [];
    private string $token        = '';
    private bool   $dryRun       = false;
    private string $employeeId   = '';

    public function __construct(
        string $url,
        string $database,
        string $username,
        string $password,
        bool   $dryRun     = false,
        string $employeeId = ''
    ) {
        $this->baseUrl    = rtrim($url, '/');
        $this->database   = $database;
        $this->username   = $username;
        $this->password   = $password;
        $this->dryRun     = $dryRun;
        $this->employeeId = $employeeId;
        $this->token = $this->authenticate();
        $this->loadValueLists();
    }

    public function getProjects(): array        { return $this->projects; }
    public function getDisciplines(): array     { return $this->disciplines; }
    public function getClassifications(): array { return $this->classifications; }

    public function writeHeader(string $name, string $week, string $year): void
    {
        $this->employeeName = $name;
        $this->weekNo       = (int) $week;
        $this->year         = (int) $year;
    }

    public function writeEntry(TimeEntry $entry, bool $withBillable = true): void
    {
        $row = ['Employee' => $this->employeeName];

        if ($this->employeeId !== '') {
            $row['EmployeeID'] = $this->employeeId;
        }
        if ($entry->date !== null) {
            $row['Date'] = $entry->date->format('m/d/Y');
        }
        $this->resolveProject($entry->project, $row);
        if ($entry->workhours !== null && $entry->workhours > 0)       { $row['WorkHours']  = $entry->workhours; }
        if ($entry->travelhours !== null && $entry->travelhours > 0)   { $row['TravelHours']= $entry->travelhours; }
        if ($entry->traveldistance !== null && $entry->traveldistance > 0) { $row['Kilometers'] = $entry->traveldistance; }
        if ($entry->parking !== null && $entry->parking > 0)           { $row['Parking']    = $entry->parking; }
        $classType = $this->resolveClassType($entry->discipline);
        if ($classType !== null) { $row['ClassType'] = $classType; }
        if ($entry->activity !== null && $entry->activity !== '') { $row['Activity'] = $entry->activity; }
        $this->pendingRows[] = $row;
    }

    public function save(string $file = ''): void
    {
        try {
            if (empty($this->pendingRows)) { echo "[FM] No entries to submit.\n"; return; }
            if ($this->weekNo === 0 || $this->employeeName === '') {
                throw new RuntimeException('[FM] writeHeader() must be called before save().');
            }
            if ($this->dryRun) {
                echo "[FM] DRY RUN — would submit " . count($this->pendingRows)
                    . " Job records for {$this->employeeName} week {$this->weekNo}/{$this->year}:\n";
                echo json_encode($this->pendingRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                return;
            }
            $written = 0;
            foreach ($this->pendingRows as $row) { $this->writeJobRecord($row); $written++; }
            echo "[FM] Submitted $written Job records for {$this->employeeName} week {$this->weekNo}/{$this->year}.\n";
        } finally {
            $this->logoutInternal();
        }
    }

    private function authenticate(): string
    {
        $url      = $this->apiUrl("databases/{$this->database}/sessions");
        $response = $this->request('POST', $url, new \stdClass(), [
            'Authorization: Basic ' . base64_encode("{$this->username}:{$this->password}"),
            'Content-Type: application/json',
        ]);
        if (empty($response['response']['token'])) {
            throw new RuntimeException('[FM] Authentication failed: ' . json_encode($response));
        }
        echo "[FM] Authenticated.\n";
        return $response['response']['token'];
    }

    private function logoutInternal(): void
    {
        if ($this->token === '') return;
        $this->request('DELETE', $this->apiUrl("databases/{$this->database}/sessions/{$this->token}"), null, [
            'Authorization: Bearer ' . $this->token,
        ]);
        $this->token = '';
    }

    private function loadValueLists(): void
    {
        $response = $this->request('GET', $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutTimesheet}"), null, [
            'Authorization: Bearer ' . $this->token,
        ]);
        foreach ($response['response']['valueLists'] ?? [] as $vl) {
            match ($vl['name'] ?? '') {
                'Projecten' => $this->parseProjecten($vl['values']),
                'Uursoort'  => $this->parseUursoort($vl['values']),
                default     => null,
            };
        }
        echo "[FM] Loaded " . count($this->projects) . " projects, " . count($this->disciplines) . " disciplines.\n";
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
        $this->classifications = array_values(array_filter(
            $this->disciplines, fn($d) => !in_array($d, ['Travel', 'Other'], true)
        ));
    }

    private function writeJobRecord(array $fieldData): void
    {
        $response = $this->request('POST', $this->apiUrl("databases/{$this->database}/layouts/{$this->layoutJob}/records"), [
            'fieldData' => $fieldData,
        ], [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ]);
        if (($response['messages'][0]['code'] ?? '-1') !== '0') {
            throw new RuntimeException('[FM] Failed to write Job record: ' . json_encode($response));
        }
    }

    private function resolveProject(?string $projectName, array &$row): void
    {
        if ($projectName === null) return;
        if (isset($this->projectCode[$projectName])) {
            $row['Projectno']   = $this->projectCode[$projectName];
            $row['Projectname'] = $projectName;
            return;
        }
        $stripped = preg_replace('/\s*\([^)]+\)\s*$/', '', $projectName);
        if ($stripped !== $projectName && isset($this->projectCode[$stripped])) {
            $row['Projectno']   = $this->projectCode[$stripped];
            $row['Projectname'] = $stripped;
            return;
        }
        echo "[FM] WARNING: No FM project code for '$projectName'\n";
        $row['Projectname'] = $projectName;
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
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
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
