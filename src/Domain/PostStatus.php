<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

enum PostStatus: string
{
    case Collecting = 'COLLECTING';
    case AwaitingTheme = 'AWAITING_THEME';
    case Generating = 'GENERATING';
    case AwaitingApproval = 'AWAITING_APPROVAL';
    case AwaitingFeedback = 'AWAITING_FEEDBACK';
    case AwaitingManualEdit = 'AWAITING_MANUAL_EDIT';
    case AwaitingImageEdit = 'AWAITING_IMAGE_EDIT';
    case ImageEditing = 'IMAGE_EDITING';
    case Scheduled = 'SCHEDULED';
    case Publishing = 'PUBLISHING';
    case Published = 'PUBLISHED';
    case Cancelled = 'CANCELLED';
    case Failed = 'FAILED';

    public function isPending(): bool
    {
        return in_array($this, [
            self::AwaitingTheme,
            self::AwaitingApproval,
            self::AwaitingFeedback,
            self::AwaitingManualEdit,
            self::AwaitingImageEdit,
        ], true);
    }
}
