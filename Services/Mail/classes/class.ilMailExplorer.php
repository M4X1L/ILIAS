<?php declare(strict_types=1);
/* Copyright (c) 1998-2012 ILIAS open source, Extended GPL, see docs/LICENSE */

use ILIAS\UI\Component\Tree\Node\Factory;
use ILIAS\UI\Component\Tree\Node\Node;
use ILIAS\UI\Component\Tree\Tree;

/**
 * Class Mail Explorer
 * class for explorer view for mailboxes
 * @author  Stefan Meyer <meyer@leifos.com>
 * @author  Michael Jansen <mjansen@databay.de>
 */
class ilMailExplorer extends ilTreeExplorerGUI
{
    private ilMailGUI $parentObject;
    protected int $currentFolderId = 0;

    /**
     * ilMailExplorer constructor.
     * @param $parentObject
     * @param $userId
     */
    public function __construct(ilMailGUI $parentObject, int $userId)
    {
        $this->parentObject = $parentObject;

        $this->tree = new ilTree($userId);
        $this->tree->setTableNames('mail_tree', 'mail_obj_data');

        parent::__construct('mail_exp', $parentObject, '', $this->tree);

        $this->initFolder();

        $this->setSkipRootNode(true);
        $this->setAjax(false);
        $this->setOrderField('title,m_type');
    }

    
    protected function initFolder() : void
    {
        $folderId = (int) ($this->httpRequest->getParsedBody()['mobj_id'] ?? 0);
        if (0 === $folderId) {
            $folderId = (int) ($this->httpRequest->getQueryParams()['mobj_id'] ?? ilSession::get('mobj_id'));
        }

        $this->currentFolderId = $folderId;
    }

    
    public function getTreeLabel() : string
    {
        return $this->lng->txt("mail_folders");
    }

    /**
     * @inheritDoc
     */
    public function getTreeComponent() : Tree
    {
        $f = $this->ui->factory();

        $tree = $f->tree()
            ->expandable($this->getTreeLabel(), $this)
            ->withData($this->tree->getChilds($this->tree->readRootId()))
            ->withHighlightOnNodeClick(false);

        return $tree;
    }

    /**
     * @inheritDoc
     */
    public function build(
        Factory $factory,
        $record,
        $environment = null
    ) : Node {
        $node = parent::build($factory, $record, $environment);

        return $node->withHighlighted($this->currentFolderId === (int) $record['child']);
    }

    /**
     * @inheritDoc
     */
    protected function getNodeStateToggleCmdClasses($record) : array
    {
        return [
            'ilMailGUI',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getNodeContent($a_node) : string
    {
        $content = $a_node['title'];

        if ($a_node['child'] === $this->getNodeId($this->getRootNode())) {
            $content = $this->lng->txt('mail_folders');
        } elseif ($a_node['depth'] < 3) {
            $content = $this->lng->txt('mail_' . $a_node['title']);
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function getNodeIcon($a_node) : string
    {
        if ($a_node['child'] === $this->getNodeId($this->getRootNode())) {
            $icon = ilUtil::getImagePath('icon_mail.svg');
        } else {
            $iconType = $a_node['m_type'];
            if ($a_node['m_type'] === 'user_folder') {
                $iconType = 'local';
            }

            $icon = ilUtil::getImagePath('icon_' . $iconType . '.svg');
        }

        return $icon;
    }

    /**
     * @inheritDoc
     */
    public function getNodeHref($a_node) : string
    {
        if ($a_node['child'] === $this->getNodeId($this->getRootNode())) {
            $a_node['child'] = 0;
        }

        $this->ctrl->setParameterByClass('ilMailFolderGUI', 'mobj_id', $a_node['child']);
        $href = $this->ctrl->getLinkTargetByClass(['ilMailGUI', 'ilMailFolderGUI']);
        $this->ctrl->clearParametersByClass('ilMailFolderGUI');

        return $href;
    }
}
