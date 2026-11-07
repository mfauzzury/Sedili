<?php

namespace App\Modules\Leave\Http;

use App\Models\Employee;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Services\LeaveAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the document attached to a leave request, only to those who may see the request.
 */
class LeaveAttachmentController
{
    public function __invoke(Request $request, Leave $leave): Response
    {
        $user = $request->user();
        // Authorization first, so that nobody learns which requests have a document
        abort_unless($user instanceof Employee && LeaveAttachment::canView($user, $leave), 403);

        $path = (string) $leave->attachment;
        abort_unless(
            LeaveAttachment::isValidPath($path) && Storage::disk(LeaveAttachment::DISK)->exists($path),
            404
        );

        return Storage::disk(LeaveAttachment::DISK)->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
