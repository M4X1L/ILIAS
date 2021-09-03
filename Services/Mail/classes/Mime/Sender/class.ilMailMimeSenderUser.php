<?php declare(strict_types=1);
/* Copyright (c) 1998-2017 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Class ilMailMimeSenderSystem
 * @author Michael Jansen <mjansen@databay.de>
 */
abstract class ilMailMimeSenderUser implements ilMailMimeSender
{
    protected ilSetting $settings;
    protected ilObjUser $user;

    /**
     * ilMailMimeSenderSystem constructor.
     * @param ilObjUser ilObjUser
     */
    public function __construct(ilSetting $settings, ilObjUser $user)
    {
        $this->settings = $settings;
        $this->user = $user;
    }

    /**
     * @inheritdoc
     */
    public function hasReplyToAddress() : bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getReplyToAddress() : string
    {
        if (
            true === (bool) $this->settings->get('use_global_reply_to_addr') &&
            is_string($this->settings->get('global_reply_to_addr', '')) &&
            ((string) $this->settings->get('global_reply_to_addr', '')) !== ''
        ) {
            return (string) $this->settings->get('global_reply_to_addr');
        }

        return (string) $this->user->getEmail();
    }

    /**
     * @inheritdoc
     */
    public function getReplyToName() : string
    {
        return (string) $this->user->getFullname();
    }

    /**
     * @inheritdoc
     */
    public function hasEnvelopFromAddress() : bool
    {
        return ((string) $this->settings->get('mail_system_usr_env_from_addr', '')) !== '';
    }

    /**
     * @inheritdoc
     */
    public function getEnvelopFromAddress() : string
    {
        return $this->settings->get('mail_system_usr_env_from_addr', '');
    }

    /**
     * @inheritdoc
     */
    public function getFromAddress() : string
    {
        return $this->settings->get('mail_system_usr_from_addr', '');
    }

    /**
     * @inheritdoc
     */
    public function getFromName() : string
    {
        $from = ((string) $this->settings->get('mail_system_usr_from_name', ''));
        if ($from === '') {
            return (string) $this->user->getFullname();
        }

        $name = str_ireplace('[FULLNAME]', (string) $this->user->getFullname(), $from);
        $name = str_ireplace('[FIRSTNAME]', (string) $this->user->getFirstname(), $name);
        $name = str_ireplace('[LASTNAME]', (string) $this->user->getLastname(), $name);
        if ($name !== $from) {
            return $name;
        }

        return $from;
    }
}
