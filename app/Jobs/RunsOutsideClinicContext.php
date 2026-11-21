<?php

namespace App\Jobs;

/**
 * A platform job that names its clinic itself (for example an import batch) and must not carry
 * the clinic the platform admin happened to be signed in to. Queued without a tenant context.
 */
interface RunsOutsideClinicContext
{
}
