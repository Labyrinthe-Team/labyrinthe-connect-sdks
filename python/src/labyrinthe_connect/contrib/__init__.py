"""Optional framework integrations (install the matching extra).

- :mod:`labyrinthe_connect.contrib.django` — ``pip install "labyrinthe-connect[django]"``
- :mod:`labyrinthe_connect.contrib.fastapi` — ``pip install "labyrinthe-connect[fastapi]"``
- :mod:`labyrinthe_connect.contrib.flask` — ``pip install "labyrinthe-connect[flask]"``
"""

from __future__ import annotations

import os
from typing import Any, Dict, Optional

from ..errors import InvalidArgumentError, LabyrintheError

#: Environment variable read when no secret is configured.
SECRET_ENV = "LABYRINTHE_WEBHOOK_SECRET"  # noqa: S105 - variable name, not a secret


def resolve_secret(*candidates: Optional[str]) -> str:
    """Premier secret non vide parmi ``candidates``, puis ``LABYRINTHE_WEBHOOK_SECRET``."""
    for candidate in (*candidates, os.environ.get(SECRET_ENV)):
        if isinstance(candidate, str) and candidate.strip():
            return candidate
    raise InvalidArgumentError(f"No webhook secret: pass `secret` or set {SECRET_ENV}.")


def error_body(error: LabyrintheError) -> Dict[str, Any]:
    """Corps JSON d'une réponse 400 (jamais le corps reçu ni le secret)."""
    return {"error": {"code": error.code, "message": error.message}}
