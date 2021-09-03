<?php declare(strict_types=1);

/* Copyright (c) 1998-2015 ILIAS open source, Extended GPL, see docs/LICENSE */

use OrgUnit\PublicApi\OrgUnitUserService;
use OrgUnit\User\ilOrgUnitUser;

/**
 * Class ilMailTemplateContext
 * @author  Michael Jansen <mjansen@databay.de>
 * @ingroup ServicesMail
 */
abstract class ilMailTemplateContext
{
    protected ?ilLanguage $language = null;
    protected ?ilMailEnvironmentHelper $envHelper;
    protected ?ilMailLanguageHelper $languageHelper;
    protected ?ilMailUserHelper $userHelper;
    protected ?OrgUnitUserService $orgUnitUserService;

    /**
     * ilMailTemplateContext constructor.
     */
    public function __construct(
        OrgUnitUserService $orgUnitUserService = null,
        ilMailEnvironmentHelper $envHelper = null,
        ilMailUserHelper $usernameHelper = null,
        ilMailLanguageHelper $languageHelper = null
    ) {
        if (null === $orgUnitUserService) {
            $orgUnitUserService = new OrgUnitUserService();
        }
        $this->orgUnitUserService = $orgUnitUserService;

        if (null === $envHelper) {
            $envHelper = new ilMailEnvironmentHelper();
        }
        $this->envHelper = $envHelper;

        if (null === $usernameHelper) {
            $usernameHelper = new ilMailUserHelper();
        }
        $this->userHelper = $usernameHelper;

        if (null === $languageHelper) {
            $languageHelper = new ilMailLanguageHelper();
        }
        $this->languageHelper = $languageHelper;
    }

    
    public function getLanguage() : ilLanguage
    {
        return $this->language ?: $this->languageHelper->getCurrentLanguage();
    }

    /**
     * Returns a unique (in the context of mail template contexts) id
     */
    abstract public function getId() : string;

    /**
     * Returns a translated title (depending on the current language) which is displayed in the user interface
     */
    abstract public function getTitle() : string;

    /**
     * Returns a translated description (depending on the current language) which is displayed in the user interface
     */
    abstract public function getDescription() : string;

    
    private function getGenericPlaceholders() : array
    {
        return [
            'mail_salutation' => [
                'placeholder' => 'MAIL_SALUTATION',
                'label' => $this->getLanguage()->txt('mail_nacc_salutation'),
            ],
            'first_name' => [
                'placeholder' => 'FIRST_NAME',
                'label' => $this->getLanguage()->txt('firstname'),
            ],
            'last_name' => [
                'placeholder' => 'LAST_NAME',
                'label' => $this->getLanguage()->txt('lastname'),
            ],
            'login' => [
                'placeholder' => 'LOGIN',
                'label' => $this->getLanguage()->txt('mail_nacc_login'),
            ],
            'title' => [
                'placeholder' => 'TITLE',
                'label' => $this->getLanguage()->txt('mail_nacc_title'),
                'supportsCondition' => true,
            ],
            'firstname_last_name_superior' => [
                'placeholder' => 'FIRSTNAME_LASTNAME_SUPERIOR',
                'label' => $this->getLanguage()->txt('mail_firstname_last_name_superior'),
            ],
            'ilias_url' => [
                'placeholder' => 'ILIAS_URL',
                'label' => $this->getLanguage()->txt('mail_nacc_ilias_url'),
            ],
            'client_name' => [
                'placeholder' => 'CLIENT_NAME',
                'label' => $this->getLanguage()->txt('mail_nacc_client_name'),
            ],
        ];
    }

    /**
     * Return an array of placeholders
     */
    final public function getPlaceholders() : array
    {
        $placeholders = $this->getGenericPlaceholders();
        $specific = $this->getSpecificPlaceholders();

        return $placeholders + $specific;
    }

    /**
     * Return an array of placeholders
     */
    abstract public function getSpecificPlaceholders() : array;

    
    abstract public function resolveSpecificPlaceholder(
        string $placeholder_id,
        array $context_parameters,
        ilObjUser $recipient = null,
        bool $html_markup = false
    ) : string;

    /**
     * @param string $placeholder_id The unique (in the context of your class) placeholder id
     * @param array $context_parameters The context parameters given by the mail system (array of key/value pairs)
     * @param ilObjUser|null $recipient The recipient for this mail
     * @param bool $html_markup A flag whether or not the return value may contain HTML markup
     */
    public function resolvePlaceholder(
        string $placeholder_id,
        array $context_parameters,
        ilObjUser $recipient = null,
        bool $html_markup = false
    ) : string {
        if ($recipient !== null) {
            $this->initLanguage($recipient);
        }

        $resolved = '';

        switch (true) {
            case ('mail_salutation' === $placeholder_id && $recipient !== null):
                $resolved = $this->getLanguage()->txt('mail_salutation_n');
                switch ($recipient->getGender()) {
                    case 'f':
                        $resolved = $this->getLanguage()->txt('mail_salutation_f');
                        break;

                    case 'm':
                        $resolved = $this->getLanguage()->txt('mail_salutation_m');
                        break;

                    case 'n':
                        $resolved = $this->getLanguage()->txt('mail_salutation_n');
                        break;
                }
                break;

            case ('first_name' === $placeholder_id && $recipient !== null):
                $resolved = $recipient->getFirstname();
                break;

            case ('last_name' === $placeholder_id && $recipient !== null):
                $resolved = $recipient->getLastname();
                break;

            case ('login' === $placeholder_id && $recipient !== null):
                $resolved = $recipient->getLogin();
                break;

            case ('title' === $placeholder_id && $recipient !== null):
                $resolved = $recipient->getUTitle();
                break;

            case 'ilias_url' === $placeholder_id:
                $resolved = $this->envHelper->getHttpPath() . '/login.php?client_id=' . $this->envHelper->getClientId();
                break;

            case 'client_name' === $placeholder_id:
                $resolved = $this->envHelper->getClientId();
                break;

            case 'firstname_last_name_superior' === $placeholder_id && $recipient !== null:
                $ouUsers = $this->orgUnitUserService->getUsers([$recipient->getId()], true);
                foreach ($ouUsers as $ouUser) {
                    $superiors = $ouUser->getSuperiors();

                    $superiorUsrIds = array_map(static function (ilOrgUnitUser $ouUser) : int {
                        return $ouUser->getUserId();
                    }, $superiors);

                    $usrIdByNameMap = $this->userHelper->getUsernameMapForIds($superiorUsrIds);

                    $resolved = implode(', ', $usrIdByNameMap);
                    break;
                }
                break;

            case !array_key_exists($placeholder_id, $this->getGenericPlaceholders()):
                $datePresentationLanguage = ilDatePresentation::getLanguage();
                ilDatePresentation::setLanguage($this->getLanguage());

                $resolved = $this->resolveSpecificPlaceholder(
                    $placeholder_id,
                    $context_parameters,
                    $recipient,
                    $html_markup
                );

                ilDatePresentation::setLanguage($datePresentationLanguage);
                break;
        }

        return $resolved;
    }

    
    protected function initLanguage(ilObjUser $user) : void
    {
        $this->initLanguageByIso2Code($user->getLanguage());
    }

    
    protected function initLanguageByIso2Code(string $isoCode) : void
    {
        $this->language = $this->languageHelper->getLanguageByIsoCode($isoCode);
        $this->language->loadLanguageModule('mail');
    }
}
