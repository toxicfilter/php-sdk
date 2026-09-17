<?php

namespace ToxicFilter\Exception;

/**
 * The key was missing, unrecognised or revoked. Nothing to retry: a wrong key stays wrong.
 */
class AuthenticationError extends ApiError
{
}
