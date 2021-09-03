<?php declare(strict_types=1);
/* Copyright (c) 1998-2014 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once 'Services/Search/classes/class.ilSearchSettings.php';

/**
 * Class ilMailAutoCompleteRecipientResult
 */
class ilMailAutoCompleteRecipientResult
{
    public const MODE_STOP_ON_MAX_ENTRIES = 1;
    public const MODE_FETCH_ALL = 2;

    public const MAX_RESULT_ENTRIES = 1000;

    protected bool $allow_smtp;
    protected int $user_id;
    protected array $handled_recipients = [];
    protected int $mode = self::MODE_STOP_ON_MAX_ENTRIES;
    protected int $max_entries;
    public array $result = [];

    
    public function __construct(int $mode)
    {
        global $DIC;

        $this->allow_smtp = $DIC->rbac()->system()->checkAccess('smtp_mail', MAIL_SETTINGS_ID);
        $this->user_id = $DIC->user()->getId();
        $this->max_entries = ilSearchSettings::getInstance()->getAutoCompleteLength();
        
        $this->result['items'] = [];
        $this->result['hasMoreResults'] = false;

        $this->initMode($mode);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function initMode(int $mode) : void
    {
        if (!in_array($mode, [self::MODE_FETCH_ALL, self::MODE_STOP_ON_MAX_ENTRIES])) {
            throw new InvalidArgumentException("Wrong mode passed!");
        }
        $this->mode = $mode;
    }

    
    public function isResultAddable() : bool
    {
        if ($this->mode === self::MODE_STOP_ON_MAX_ENTRIES &&
        $this->max_entries >= 0 && count($this->result['items']) >= $this->max_entries) {
            return false;
        }

        if (
            $this->mode === self::MODE_FETCH_ALL &&
            count($this->result['items']) >= self::MAX_RESULT_ENTRIES
        ) {
            return false;
        }
        return true;
    }

    
    public function addResult(string $login, string $firstname, string $lastname) : void
    {
        if (!isset($this->handled_recipients[$login])) {
            $recipient = [];
            $recipient['value'] = $login;

            $label = $login;
            if ($firstname && $lastname) {
                $label .= " [" . $firstname . ", " . $lastname . "]";
            }
            $recipient['label'] = $label;

            $this->result['items'][] = $recipient;
            $this->handled_recipients[$login] = 1;
        }
    }

    
    public function getItems() : array
    {
        return $this->result;
    }

    
    public function numItems() : int
    {
        return count($this->result['items']);
    }
}
