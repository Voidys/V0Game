<?php

declare(strict_types=1);

namespace App\Game\Engagement\Contracts;

/** A resource reward is due but nothing can be paid (no producing planet): ContractBoard::claim() rolls the claim back. */
final class NothingToPay extends \RuntimeException {}
