<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the three efactura tables with their key columns', function () {
    expect(Schema::hasTable('efactura_authorisations'))->toBeTrue()
        ->and(Schema::hasColumns('efactura_authorisations', ['started_for_cui', 'covered_cuis', 'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at', 'authorised_at', 'refreshed_at', 'label']))->toBeTrue()
        ->and(Schema::hasTable('efactura_submissions'))->toBeTrue()
        ->and(Schema::hasColumns('efactura_submissions', ['cui', 'standard', 'document_type', 'document_number', 'subject_type', 'subject_id', 'xml', 'b2c', 'upload_options', 'upload_index', 'download_id', 'phase', 'attempts', 'next_poll_at', 'last_error', 'bundle_path', 'submitted_at', 'resolved_at']))->toBeTrue()
        ->and(Schema::hasTable('efactura_inbox_messages'))->toBeTrue()
        ->and(Schema::hasColumns('efactura_inbox_messages', ['cui', 'anaf_id', 'type', 'request_index', 'seller_cui', 'buyer_cui', 'details', 'anaf_created_at', 'bundle_path', 'parsed_at', 'document_number', 'document_date', 'document_currency', 'document_total']))->toBeTrue();
});

it('reports which engine the suite ran on', function () {
    $driver = Schema::getConnection()->getDriverName();

    expect($driver)->toBeIn(['sqlite', 'pgsql', 'mysql']);
    fwrite(STDERR, "\n  [efactura tests on {$driver}]\n");
});
