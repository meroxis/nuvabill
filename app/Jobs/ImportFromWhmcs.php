<?php

namespace App\Jobs;

/**
 * The import job before 0.4.9, when only WHMCS could be imported. Kept so pieces queued by an older
 * version still run after an update.
 *
 * @deprecated Use {@see RunImport}.
 */
class ImportFromWhmcs extends RunImport {}
