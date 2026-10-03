<?php

namespace App\Contracts;

/**
 * A gateway that can look up an unclear automatic payment only by the ID it sent back (see
 * ChecksSavedCharges). When a try ended without that ID, Nuvabill sends that same try again with
 * the same attempt key, also when staff press "Charge now", never a new one. The gateway's own
 * guard against repeats (for example PayPal's invoice ID) can then stop a second payment.
 */
interface RepeatsUnclearCharges {}
