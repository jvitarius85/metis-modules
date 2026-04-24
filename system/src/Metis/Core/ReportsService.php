<?php
if (!defined('METIS_ROOT')) exit;

/**
 * Core Reports Service
 * Centralized reporting engine for any module
 * Handles:
 *  - SQL building
 *  - KPI calculations
 *  - Period comparisons (MoM / QoQ / YoY)
 *  - Future campaign & donor extensions
 */
if ( class_exists( 'Metis_Logger' ) ) Metis_Logger::info( 'Reports Service loaded' );

class Core_Reports_Service {

    private $allowed_status = [];

    private $db;
    private $table;

    public function __construct($table = null) {
        $this->db = function_exists('metis_db') ? metis_db() : null;

        if (!$table) {
            throw new InvalidArgumentException('Reports service requires a table name.');
        }

        $this->table = $table;

        // Load payment statuses from settings service
        if (class_exists('Core_Settings_Service')) {
            $statuses = Core_Settings_Service::get('payment_statuses', []);
            if (is_array($statuses)) {
                $this->allowed_status = $statuses;
            }
        }
    }

    /* =========================================================
       PUBLIC ENTRYPOINT
    ========================================================= */

    public function run(array $args = []) {

        $defaults = [
            'start'    => null,
            'end'      => null,
            'group'    => 'month',
            'platform' => null,
            'status'   => null,
        ];

        $args = metis_runtime_parse_args($args, $defaults);

        $rows = $this->query($args);
        $kpis = $this->calculate_kpis($rows);
        $comparison = [];
        if (!empty($args['compare']) && $args['compare'] !== 'none') {
            $comparison = $this->calculate_comparison($args, $args['compare']);
        }

        return [
            'rows'       => $rows,
            'kpis'       => $kpis,
            'comparison' => $comparison,
        ];
    }

    /* =========================================================
       DONOR INTELLIGENCE
    ========================================================= */

    public function run_donor_intelligence(array $args = []) {

        $defaults = [
            'start'      => null,
            'end'        => null,
            'platform'   => null,
            'status'     => 'completed',
            'limit'      => 50,
            'lifetime'   => true,
        ];

        $args = metis_runtime_parse_args($args, $defaults);

        $rows       = $this->query_donors($args);
        $kpis       = $this->calculate_donor_kpis($rows);
        $retention  = [];

        if (!$args['lifetime'] && !empty($args['start']) && !empty($args['end'])) {
            $retention = $this->calculate_donor_retention($args);
        }

        return [
            'rows'      => $rows,
            'kpis'      => $kpis,
            'retention' => $retention,
        ];
    }

    private function query_donors(array $args) {

        $where  = ['t.amount IS NOT NULL'];
        $params = [];

        if (!$args['lifetime']) {
            if (!empty($args['start'])) {
                $where[] = 't.tran_date >= %s';
                $params[] = $args['start'];
            }
            if (!empty($args['end'])) {
                $where[] = 't.tran_date <= %s';
                $params[] = $args['end'];
            }
        }

        if (!empty($args['platform']) && $args['platform'] !== 'ALL') {
            $where[] = 't.platform = %s';
            $params[] = strtoupper($args['platform']);
        }

        if (!empty($args['status']) && strtoupper($args['status']) !== 'ALL') {

            $status = strtolower($args['status']);

            if (in_array($status, $this->allowed_status, true)) {
                $where[] = 't.status = %s';
                $params[] = $status;
            }
        }

        $limit = intval($args['limit']);

        $sql = "
            SELECT
                t.did,
                SUM(t.amount) AS gross,
                SUM(t.fee) AS fee,
                SUM(t.amount - t.fee) AS net,
                COUNT(t.id) AS donation_count,
                MIN(t.tran_date) AS first_gift,
                MAX(t.tran_date) AS last_gift
            FROM {$this->table} t
            WHERE " . implode(' AND ', $where) . "
            GROUP BY t.did
            ORDER BY gross DESC
            LIMIT {$limit}
        ";

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }

        return is_object($this->db) ? $this->db->fetchAll($sql) : [];
    }

    private function calculate_donor_kpis(array $rows) {

        $total_gross = 0;
        $total_net   = 0;
        $total_count = 0;
        $donor_count = count($rows);

        foreach ($rows as $row) {
            $total_gross += (float)$row['gross'];
            $total_net   += (float)$row['net'];
            $total_count += (int)$row['donation_count'];
        }

        $avg_gift  = $total_count ? $total_gross / $total_count : 0;
        $avg_ltv   = $donor_count ? $total_gross / $donor_count : 0;
        $frequency = $donor_count ? $total_count / $donor_count : 0;

        return [
            'gross'      => $total_gross,
            'net'        => $total_net,
            'donors'     => $donor_count,
            'gifts'      => $total_count,
            'avg_gift'   => $avg_gift,
            'avg_ltv'    => $avg_ltv,
            'frequency'  => $frequency,
        ];
    }

    private function calculate_donor_retention(array $args) {

        $current = $this->get_donors_in_period($args);

        $prev_args = $this->shift_period_by_mode($args, 'custom');
        $previous  = $this->get_donors_in_period($prev_args);

        $current_ids  = array_column($current, 'did');
        $previous_ids = array_column($previous, 'did');

        $retained = array_intersect($previous_ids, $current_ids);

        $rate = count($previous_ids)
            ? (count($retained) / count($previous_ids)) * 100
            : 0;

        return [
            'previous_count' => count($previous_ids),
            'current_count'  => count($current_ids),
            'retained'       => count($retained),
            'retention_pct'  => $rate,
        ];
    }

    private function get_donors_in_period(array $args) {

        $where  = ['amount IS NOT NULL'];
        $params = [];

        if (!empty($args['start'])) {
            $where[] = 'tran_date >= %s';
            $params[] = $args['start'];
        }

        if (!empty($args['end'])) {
            $where[] = 'tran_date <= %s';
            $params[] = $args['end'];
        }

        if (!empty($args['status']) && strtoupper($args['status']) !== 'ALL') {

            $status = strtolower($args['status']);

            if (in_array($status, $this->allowed_status, true)) {
                $where[] = 'status = %s';
                $params[] = $status;
            }
        }

        $sql = "
            SELECT DISTINCT did
            FROM {$this->table}
            WHERE " . implode(' AND ', $where);

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }

        return is_object($this->db) ? $this->db->fetchAll($sql) : [];
    }

    /* =========================================================
       SQL BUILDER
    ========================================================= */

    private function query(array $args) {

        $where = ['1=1', 'amount IS NOT NULL'];
        $params = [];

        if (!empty($args['start'])) {
            $where[] = 'tran_date >= %s';
            $params[] = $args['start'];
        }

        if (!empty($args['end'])) {
            $where[] = 'tran_date <= %s';
            $params[] = $args['end'];
        }

        if (!empty($args['platform']) && $args['platform'] !== 'ALL') {
            $where[] = 'platform = %s';
            $params[] = strtoupper($args['platform']);
        }

        if (!empty($args['status']) && strtoupper($args['status']) !== 'ALL') {

            $status = strtolower($args['status']);

            if (in_array($status, $this->allowed_status, true)) {
                $where[] = 'status = %s';
                $params[] = $status;
            }
        }

        $group_sql = $this->group_clause($args['group']);

        $sql = "
            SELECT
                {$group_sql} AS period,
                SUM(COALESCE(amount,0)) AS gross,
                SUM(COALESCE(fee,0)) AS fee,
                SUM(COALESCE(amount,0) - COALESCE(fee,0)) AS net,
                COUNT(id) AS count
            FROM {$this->table}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY period
            ORDER BY period ASC
        ";

        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }

        return is_object($this->db) ? $this->db->fetchAll($sql) : [];
    }

    private function group_clause($group) {
        switch ($group) {
            case 'year':
                return "DATE_FORMAT(tran_date, '%Y')";
            case 'quarter':
                return "CONCAT(YEAR(tran_date), '-Q', QUARTER(tran_date))";
            case 'week':
                return "DATE_FORMAT(tran_date, '%x-W%v')";
            case 'day':
                return "DATE_FORMAT(tran_date, '%Y-%m-%d')";
            case 'month':
            default:
                return "DATE_FORMAT(tran_date, '%Y-%m')";
        }
    }

    /* =========================================================
       KPI CALCULATIONS
    ========================================================= */

    private function calculate_kpis(array $rows) {

        $gross = 0;
        $fee   = 0;
        $net   = 0;
        $count = 0;

        foreach ($rows as $row) {
            $gross += (float)$row['gross'];
            $fee   += (float)$row['fee'];
            $net   += (float)$row['net'];
            $count += (int)$row['count'];
        }

        $avg = $count ? $gross / $count : 0;
        $fee_pct = $gross ? ($fee / $gross) * 100 : 0;

        return [
            'gross'   => $gross,
            'fee'     => $fee,
            'net'     => $net,
            'count'   => $count,
            'avg'     => $avg,
            'fee_pct' => $fee_pct,
        ];
    }

    /* =========================================================
       COMPARISON ENGINE (MoM / QoQ / YoY foundation)
    ========================================================= */

    private function calculate_comparison(array $args, string $mode) {

        if (empty($args['start']) || empty($args['end'])) {
            return [];
        }

        $current_rows  = $this->query($args);
        $current_total = $this->sum_net($current_rows);

        $previous_args = $this->shift_period_by_mode($args, $mode);

        $previous_rows  = $this->query($previous_args);
        $previous_total = $this->sum_net($previous_rows);

        $delta = $current_total - $previous_total;

        $percent_change = null;
        if ($previous_total != 0) {
            $percent_change = ($delta / $previous_total) * 100;
        }

        $direction = 'flat';
        if ($delta > 0)  $direction = 'up';
        if ($delta < 0)  $direction = 'down';

        return [
            'mode'           => $mode,
            'current'        => $current_total,
            'previous'       => $previous_total,
            'delta'          => $delta,
            'percent_change' => $percent_change,
            'direction'      => $direction,
        ];
    }

    private function shift_period_by_mode(array $args, string $mode) {
        $tz = metis_runtime_timezone();

        try {
            $start = new DateTimeImmutable($args['start'], $tz);
            $end   = new DateTimeImmutable($args['end'], $tz);
        } catch (Exception $e) {
            return $args;
        }

        switch ($mode) {

            case 'mom':
                $start = $start->modify('-1 month');
                $end   = $end->modify('-1 month');
                break;

            case 'qoq':
                $start = $start->modify('-3 months');
                $end   = $end->modify('-3 months');
                break;

            case 'yoy':
                $start = $start->modify('-1 year');
                $end   = $end->modify('-1 year');
                break;

            case 'custom':
            default:
                $range = $end->getTimestamp() - $start->getTimestamp();

                $prev_end   = $start->modify('-1 second');
                $prev_start = (new DateTimeImmutable('@' . ($prev_end->getTimestamp() - $range)))
                                ->setTimezone($tz);

                $start = $prev_start;
                $end   = $prev_end;
                break;
        }

        $args['start'] = metis_runtime_date('Y-m-d', $start->getTimestamp(), $tz);
        $args['end']   = metis_runtime_date('Y-m-d', $end->getTimestamp(), $tz);

        return $args;
    }

    private function sum_net(array $rows) {
        $total = 0;
        foreach ($rows as $row) {
            $total += (float)$row['net'];
        }
        return $total;
    }

}
