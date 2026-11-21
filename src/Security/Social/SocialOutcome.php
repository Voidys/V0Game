<?php

declare(strict_types=1);

namespace App\Security\Social;

/** What a verified provider identity leads to (SocialResolver::resolve()). */
enum SocialOutcome
{
    /** the identity is linked to an account: log it in */
    case Login;
    /** connected to the logged-in account (intent link) */
    case Linked;
    /** intent link, but the identity belongs to another account */
    case Taken;
    /** not linked, an account has the same verified e-mail address: ask for its password once */
    case LinkWithPassword;
    /** not linked, no account with the address: short registration */
    case Register;
    /** not linked and no verified e-mail address to go on: nothing can be done here */
    case NoEmail;
    /** link step: the password of the account was wrong */
    case WrongPassword;
}
