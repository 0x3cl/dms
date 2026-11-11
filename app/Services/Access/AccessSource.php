<?php

namespace App\Services\Access;

/** Why a grant was given. Recorded on the audit row beside the action. */
enum AccessSource: string
{
    case Denied = 'denied';
    case Owner = 'owner';
    case Uploader = 'uploader';
    case Recipient = 'recipient';
    case PublicItem = 'public';
    case Share = 'share';
    case InheritedShare = 'inherited_share';
    case Link = 'link';
    case ModuleManage = 'module_manage';
}
