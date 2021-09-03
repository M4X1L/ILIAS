<?php declare(strict_types=1);
/* Copyright (c) 1998-2018 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Class ilMailDiffAddressList
 * @author Michael Jansen <mjansen@databay.de>
 */
class ilMailDiffAddressList implements ilMailAddressList
{
    protected ilMailAddressList $left;
    protected ilMailAddressList $right;

    /**
     * ilMailDiffAddressList constructor.
     */
    public function __construct(ilMailAddressList $left, ilMailAddressList $right)
    {
        $this->left = $left;
        $this->right = $right;
    }


    /**
     * @inheritdoc
     */
    public function value() : array
    {
        $leftAddresses = $this->left->value();
        $rightAddresses = $this->right->value();

        return array_diff($leftAddresses, $rightAddresses);
    }
}
