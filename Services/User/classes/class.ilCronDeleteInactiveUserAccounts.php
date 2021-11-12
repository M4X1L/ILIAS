<?php
/* Copyright (c) 1998-2009 ILIAS open source, Extended GPL, see docs/LICENSE */

include_once "Services/Cron/classes/class.ilCronJob.php";
include_once 'Services/Mail/classes/class.ilMimeMail.php';
include_once 'Services/User/classes/class.ilCronDeleteInactiveUserReminderMail.php';

/**
 * This cron deletes user accounts by INACTIVITY period
 * @author Bjoern Heyser <bheyser@databay.de>
 * @author Guido Vollbach <gvollbach@databay.de>
 * @package ilias
 */
class ilCronDeleteInactiveUserAccounts extends ilCronJob
{
    private const DEFAULT_INACTIVITY_PERIOD = 365;
    private const DEFAULT_REMINDER_PERIOD = 0;

    private int $period;
    private int $reminderTimer;
    /** @var int[] */
    private ?array $include_roles;
    private ilSetting $settings;
    private ilLanguage $lng;
    private \ILIAS\HTTP\GlobalHttpState $http;
    private \ILIAS\Refinery\Factory $refinery;

    public function __construct()
    {
        global $DIC;

        if ($DIC) {
            if (isset($DIC['http'])) {
                $this->http = $DIC->http();
            }

            if (isset($DIC['lng'])) {
                $this->lng = $DIC->language();
            }

            if (isset($DIC['refinery'])) {
                $this->refinery = $DIC->refinery();
            }

            if (isset($DIC['ilSetting'])) {
                $this->settings = $DIC->settings();

                $include_roles = $DIC['ilSetting']->get(
                    'cron_inactive_user_delete_include_roles',
                    null
                );
                if ($include_roles === null) {
                    $this->include_roles = [];
                } else {
                    $this->include_roles = array_filter(array_map('intval', explode(',', $include_roles)));
                }

                $this->period = (int) $DIC['ilSetting']->get(
                    'cron_inactive_user_delete_period',
                    (string) self::DEFAULT_INACTIVITY_PERIOD
                );
                $this->reminderTimer = (int) $DIC['ilSetting']->get(
                    'cron_inactive_user_reminder_period',
                    (string) self::DEFAULT_REMINDER_PERIOD
                );
            }
        }
    }

    protected function isDecimal($number) : bool
    {
        $number = (string) $number;

        return strpos($number, ',') || strpos($number, '.');
    }

    protected function getTimeDifferenceBySchedule(int $schedule_time, int $multiplier) : int
    {
        $time_difference = 0;

        switch ($schedule_time) {
            case ilCronJob::SCHEDULE_TYPE_DAILY:
                $time_difference = 86400;
                break;
            case ilCronJob::SCHEDULE_TYPE_IN_MINUTES:
                $time_difference = 60 * $multiplier;
                break;
            case ilCronJob::SCHEDULE_TYPE_IN_HOURS:
                $time_difference = 3600 * $multiplier;
                break;
            case ilCronJob::SCHEDULE_TYPE_IN_DAYS:
                $time_difference = 86400 * $multiplier;
                break;
            case ilCronJob::SCHEDULE_TYPE_WEEKLY:
                $time_difference = 604800;
                break;
            case ilCronJob::SCHEDULE_TYPE_MONTHLY:
                $time_difference = 2629743;
                break;
            case ilCronJob::SCHEDULE_TYPE_QUARTERLY:
                $time_difference = 7889229;
                break;
            case ilCronJob::SCHEDULE_TYPE_YEARLY:
                $time_difference = 31556926;
                break;
        }

        return $time_difference;
    }

    public function getId() : string
    {
        return "user_inactive";
    }
    
    public function getTitle() : string
    {
        global $DIC;

        $lng = $DIC['lng'];
        
        return $lng->txt("delete_inactive_user_accounts");
    }
    
    public function getDescription() : string
    {
        global $DIC;

        $lng = $DIC['lng'];
        
        return $lng->txt("delete_inactive_user_accounts_desc");
    }
    
    public function getDefaultScheduleType() : int
    {
        return self::SCHEDULE_TYPE_DAILY;
    }
    
    public function getDefaultScheduleValue() : ?int
    {
        return null;
    }
    
    public function hasAutoActivation() : bool
    {
        return false;
    }
    
    public function hasFlexibleSchedule() : bool
    {
        return true;
    }
    
    public function hasCustomSettings() : bool
    {
        return true;
    }
    
    public function run() : ilCronJobResult
    {
        global $DIC;

        $rbacreview = $DIC->rbac()->review();
        $ilLog = $DIC['ilLog'];

        $status = ilCronJobResult::STATUS_NO_ACTION;
        $reminder_time = $this->reminderTimer;
        $checkMail = $this->period - $reminder_time;
        $usr_ids = ilObjUser::getUserIdsByInactivityPeriod($checkMail);
        $counter = 0;
        $userDeleted = 0;
        $userMailsDelivered = 0;
        foreach ($usr_ids as $usr_id) {
            if ($usr_id === ANONYMOUS_USER_ID || $usr_id === SYSTEM_USER_ID) {
                continue;
            }

            $continue = true;
            foreach ($this->include_roles as $role_id) {
                if ($rbacreview->isAssigned($usr_id, $role_id)) {
                    $continue = false;
                    break;
                }
            }

            if ($continue) {
                continue;
            }

            /** @var $user ilObjUser */
            $user = ilObjectFactory::getInstanceByObjId($usr_id);
            $timestamp_last_login = strtotime($user->getLastLogin());
            $grace_period_over = time() - ($this->period * 24 * 60 * 60);
            if ($timestamp_last_login < $grace_period_over) {
                $user->delete();
                $userDeleted++;
            } elseif ($reminder_time > 0) {
                $timestamp_for_deletion = $timestamp_last_login - $grace_period_over;
                $account_will_be_deleted_on = $this->calculateDeletionData($timestamp_for_deletion);
                $mailSent = ilCronDeleteInactiveUserReminderMail::checkIfReminderMailShouldBeSend(
                    $user,
                    $reminder_time,
                    $account_will_be_deleted_on
                );
                if ($mailSent) {
                    $userMailsDelivered++;
                }
            }
            $counter++;
        }
        
        if ($counter) {
            $status = ilCronJobResult::STATUS_OK;
        }

        ilCronDeleteInactiveUserReminderMail::removeEntriesFromTableIfLastLoginIsNewer();
        $ilLog->write(
            "CRON - ilCronDeleteInactiveUserAccounts::run(), deleted " .
            "=> $userDeleted User(s), sent reminder mail to $userMailsDelivered User(s)"
        );

        $result = new ilCronJobResult();
        $result->setStatus($status);

        return $result;
    }
    
    protected function calculateDeletionData(int $date_for_deletion) : int
    {
        $cron_timing = ilCronManager::getCronJobData($this->getId());
        $time_difference = 0;
        $multiplier = 1;

        if (!is_array($cron_timing) || !is_array($cron_timing[0])) {
            return time() + $date_for_deletion + $time_difference;
        }

        if (array_key_exists('schedule_type', $cron_timing[0])) {
            if ($cron_timing[0]['schedule_value'] !== null) {
                $multiplier = (int) $cron_timing[0]['schedule_value'];
            }
            $time_difference = $this->getTimeDifferenceBySchedule(
                (int) $cron_timing[0]['schedule_type'],
                $multiplier
            );
        }
        return time() + $date_for_deletion + $time_difference;
    }
    
    public function addCustomSettingsToForm(ilPropertyFormGUI $a_form) : void
    {
        global $DIC;

        $lng = $DIC->language();
        $rbacreview = $DIC->rbac()->review();
        $ilObjDataCache = $DIC['ilObjDataCache'];
        $ilSetting = $DIC->settings();

        $lng->loadLanguageModule("user");
            
        $schedule = $a_form->getItemByPostVar('type');
        $schedule->setTitle($lng->txt('delete_inactive_user_accounts_frequency'));
        $schedule->setInfo($lng->txt('delete_inactive_user_accounts_frequency_desc'));

        $sub_mlist = new ilMultiSelectInputGUI(
            $lng->txt('delete_inactive_user_accounts_include_roles'),
            'cron_inactive_user_delete_include_roles'
        );
        $sub_mlist->setInfo($lng->txt('delete_inactive_user_accounts_include_roles_desc'));
        $roles = [];
        foreach ($rbacreview->getGlobalRoles() as $role_id) {
            if ($role_id !== ANONYMOUS_ROLE_ID) {
                $roles[$role_id] = $ilObjDataCache->lookupTitle($role_id);
            }
        }
        $sub_mlist->setOptions($roles);
        $setting = $ilSetting->get('cron_inactive_user_delete_include_roles', null);
        if ($setting === null) {
            $setting = [];
        } else {
            $setting = explode(',', $setting);
        }
        $sub_mlist->setValue($setting);
        $sub_mlist->setWidth(300);
        $a_form->addItem($sub_mlist);

        $default_setting = (string) self::DEFAULT_INACTIVITY_PERIOD;

        $sub_text = new ilNumberInputGUI(
            $lng->txt('delete_inactive_user_accounts_period'),
            'cron_inactive_user_delete_period'
        );
        $sub_text->setInfo($lng->txt('delete_inactive_user_accounts_period_desc'));
        $sub_text->setValue($ilSetting->get("cron_inactive_user_delete_period", $default_setting));
        $sub_text->setSize(4);
        $sub_text->setMaxLength(4);
        $sub_text->setRequired(true);
        $a_form->addItem($sub_text);

        $sub_period = new ilNumberInputGUI(
            $lng->txt('send_mail_to_inactive_users'),
            'cron_inactive_user_reminder_period'
        );
        $sub_period->setInfo($lng->txt("send_mail_to_inactive_users_desc"));
        $sub_period->setValue($ilSetting->get("cron_inactive_user_reminder_period", $default_setting));
        $sub_period->setSuffix($lng->txt("send_mail_to_inactive_users_suffix"));
        $sub_period->setSize(4);
        $sub_period->setMaxLength(4);
        $sub_period->setRequired(false);
        $sub_period->setMinValue(0);
        $a_form->addItem($sub_period);
    }

    public function saveCustomSettings(ilPropertyFormGUI $a_form) : bool
    {
        global $DIC;

        $ilSetting = $DIC->settings();
        $lng = $DIC->language();

        $lng->loadLanguageModule("user");

        $setting = implode(',', (array) ($_POST['cron_inactive_user_delete_include_roles'] ?? []));

        $valid = true;
        $delete_period = ilUtil::stripSlashes($_POST['cron_inactive_user_delete_period'] ?? '');
        $reminder_period = ilUtil::stripSlashes($_POST['cron_inactive_user_reminder_period'] ?? '');
        $cron_period = (int) ilUtil::stripSlashes($_POST['type'] ?? '');
        $cron_period_custom = (int) ilUtil::stripSlashes($_POST['sdyi'] ?? '');

        if ($this->isDecimal($delete_period)) {
            $valid = false;
            $a_form->getItemByPostVar('cron_inactive_user_delete_period')->setAlert($lng->txt('send_mail_to_inactive_users_numbers_only'));
        }
        if ($this->isDecimal($reminder_period)) {
            $valid = false;
            $a_form->getItemByPostVar('cron_inactive_user_reminder_period')->setAlert($lng->txt('send_mail_to_inactive_users_numbers_only'));
        }
        if ($reminder_period >= $delete_period) {
            $valid = false;
            $a_form->getItemByPostVar('cron_inactive_user_reminder_period')->setAlert($lng->txt('send_mail_to_inactive_users_must_be_smaller_than'));
        }
        if ($cron_period >= ilCronJob::SCHEDULE_TYPE_IN_DAYS && $cron_period <= ilCronJob::SCHEDULE_TYPE_YEARLY && $reminder_period > 0) {
            $logic = true;
            $check_window_logic = $delete_period - $reminder_period;
            if ($cron_period === ilCronJob::SCHEDULE_TYPE_IN_DAYS) {
                if ($check_window_logic < $cron_period_custom) {
                    $logic = false;
                }
            } elseif ($cron_period === ilCronJob::SCHEDULE_TYPE_WEEKLY) {
                if ($check_window_logic <= 7) {
                    $logic = false;
                }
            } elseif ($cron_period === ilCronJob::SCHEDULE_TYPE_MONTHLY) {
                if ($check_window_logic <= 31) {
                    $logic = false;
                }
            } elseif ($cron_period === ilCronJob::SCHEDULE_TYPE_QUARTERLY) {
                if ($check_window_logic <= 92) {
                    $logic = false;
                }
            } elseif ($cron_period === ilCronJob::SCHEDULE_TYPE_YEARLY) {
                if ($check_window_logic <= 366) {
                    $logic = false;
                }
            }

            if (!$logic) {
                $valid = false;
                $a_form->getItemByPostVar('cron_inactive_user_reminder_period')->setAlert($lng->txt('send_mail_reminder_window_too_small'));
            }
        }

        if ($_POST['cron_inactive_user_delete_period']) {
            $ilSetting->set('cron_inactive_user_delete_include_roles', $setting);
            $ilSetting->set('cron_inactive_user_delete_period', $_POST['cron_inactive_user_delete_period']);
        }

        if ($this->reminderTimer > $reminder_period) {
            ilCronDeleteInactiveUserReminderMail::flushDataTable();
        }

        $ilSetting->set('cron_inactive_user_reminder_period', $reminder_period);

        if (!$valid) {
            ilUtil::sendFailure($lng->txt("form_input_not_valid"));
            return false;
        }

        return true;
    }
}
