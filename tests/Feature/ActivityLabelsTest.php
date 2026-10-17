<?php

use App\Support\ActivityFormatter;
use Spatie\Activitylog\Models\Activity;

it('names every event it logs in the Activity log', function (string $event) {
    $activity = new Activity(['event' => $event]);

    expect(ActivityFormatter::eventLabel($activity))->not->toBe($event);
})->with([
    'document-uploaded', 'document-replaced', 'document-edited', 'document-deleted', 'document-restored',
    'document-erased', 'document-viewed', 'document-number-shown', 'documents-downloaded', 'documents-exported',
    'document-numbers-cut',
]);
