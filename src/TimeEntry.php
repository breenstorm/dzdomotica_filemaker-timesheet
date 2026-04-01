<?php

namespace TimesheetEngine;

class TimeEntry
{
    public ?string $project;
    public ?\DateTime $date;
    public ?float $workhours;
    public ?float $travelhours;
    public ?float $traveldistance;
    public ?float $parking;
    public ?float $dieta;
    public ?string $activity;
    public ?string $discipline;
    public ?string $classification;
    public bool $billable;

    public function __construct(
        ?string $project = null,
        ?\DateTime $date = null,
        ?float $workhours = null,
        ?float $travelhours = null,
        ?float $traveldistance = null,
        ?float $parking = null,
        ?float $dieta = null,
        ?string $activity = null,
        ?string $discipline = null,
        ?string $classification = null,
        bool $billable = false
    ) {
        $this->project        = $project;
        $this->date           = $date;
        $this->workhours      = $workhours;
        $this->travelhours    = $travelhours;
        $this->traveldistance = $traveldistance;
        $this->parking        = $parking;
        $this->dieta          = $dieta;
        $this->activity       = $activity;
        $this->discipline     = $discipline;
        $this->classification = $classification;
        $this->billable       = $billable;
    }
}
