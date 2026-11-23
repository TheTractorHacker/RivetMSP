<?php
require_once "includes/inc_all_admin.php";

$pay_frequency_array = array(
    'weekly'      => 'Weekly',
    'biweekly'    => 'Biweekly',
    'semimonthly' => 'Semimonthly',
    'monthly'     => 'Monthly',
);

?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2"></i>Payroll Settings</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-1"></i>
            Payroll here computes <strong>gross pay only</strong> (hours &times; rate, overtime, deductions) - no
            federal/state/FICA tax withholding is calculated anywhere in this feature. Handle withholding with your
            accountant or payroll service, outside RivetMSP.
        </div>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Overtime threshold (hours per workweek)</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-business-time"></i></span>
                    </div>
                    <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}"
                        name="overtime_threshold_hours"
                        value="<?php echo number_format($config_payroll_overtime_threshold_hours, 2, '.', ''); ?>"
                        placeholder="40.00" required>
                </div>
                <small class="form-text text-muted">Hours worked beyond this in a single workweek are paid at the overtime multiplier below. Varies by state - check your local rules.</small>
            </div>

            <div class="form-group">
                <label>Overtime multiplier</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-times"></i></span>
                    </div>
                    <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}"
                        name="overtime_multiplier"
                        value="<?php echo number_format($config_payroll_overtime_multiplier, 2, '.', ''); ?>"
                        placeholder="1.50" required>
                </div>
                <small class="form-text text-muted">e.g. 1.50 for "time and a half".</small>
            </div>

            <div class="form-group">
                <label>Default pay frequency</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-alt"></i></span>
                    </div>
                    <select class="form-control" name="default_pay_frequency">
                        <?php foreach ($pay_frequency_array as $freq_value => $freq_name) { ?>
                            <option <?php if ($freq_value == $config_payroll_default_pay_frequency) { echo "selected"; } ?>
                                value="<?php echo nullable_htmlentities($freq_value); ?>">
                                <?php echo nullable_htmlentities($freq_name); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <small class="form-text text-muted">Only used to pre-fill the "New Period" end date suggestion - you can always pick different dates.</small>
            </div>

            <div class="form-group">
                <label>Workweek</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-week"></i></span>
                    </div>
                    <select class="form-control" name="workweek_start">
                        <option value="period" <?php if ($config_payroll_workweek_start === 'period') { echo "selected"; } ?>>Restart at every pay period start (legacy)</option>
                        <?php foreach (['sun' => 'Sunday', 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday'] as $wk_value => $wk_name) { ?>
                            <option value="<?php echo $wk_value; ?>" <?php if ($config_payroll_workweek_start === $wk_value) { echo "selected"; } ?>>Fixed company workweek starting <?php echo $wk_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <small class="form-text text-muted">Overtime is judged per workweek. With a fixed workweek, a week that crosses two pay periods (for example semimonthly, 15th/16th) is paid whole in the period it starts in and its overtime is counted once on the full week. The legacy setting restarts the week at every period start, so a week split by a period boundary can lose its overtime. Hours already entered are keyed by week start date; switch it between periods, not in the middle of one.</small>
            </div>

            <hr>

            <button type="submit" name="edit_payroll_settings" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
