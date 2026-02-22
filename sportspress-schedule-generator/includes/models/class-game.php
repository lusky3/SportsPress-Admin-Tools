<?php
/**
 * Game Data Model
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Game data model
 */
class SPSG_Game
{

    public $id;
    public $date;
    public $time_slot;
    public $home_team;
    public $away_team;
    public $venue;
    public $division;
    public $is_makeup = false;
    public $original_date;
    public $week_number;

    /**
     * Constructor
     */
    public function __construct($data = array())
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        if ($this->date && !$this->week_number) {
            $this->week_number = $this->calculate_week_number();
        }
    }

    /**
     * Calculate week number from date
     */
    private function calculate_week_number()
    {
        $date = new DateTime($this->date);
        return (int)$date->format('W');
    }

    /**
     * Get game as array
     */
    public function to_array()
    {
        return array(
            'id' => $this->id,
            'date' => $this->date,
            'time_slot' => $this->time_slot,
            'home_team' => $this->home_team,
            'away_team' => $this->away_team,
            'venue' => $this->venue,
            'division' => $this->division,
            'is_makeup' => $this->is_makeup,
            'original_date' => $this->original_date,
            'week_number' => $this->week_number
        );
    }
}