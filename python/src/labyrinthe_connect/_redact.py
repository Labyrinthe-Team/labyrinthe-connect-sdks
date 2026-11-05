"""Masque les secrets et les données personnelles avant un log ou un ``repr``. Usage interne."""

from __future__ import annotations

import re
from typing import Optional, Sequence, Tuple, Union
from urllib.parse import quote

from .phone import mask

#: Paramètres de requête dont la valeur est un numéro de téléphone.
PHONE_PARAMS = frozenset({"recipient", "phone"})

_KEY_PREFIX = re.compile(r"^(lsk_(?:live|test)_)")
_QUERY = re.compile(r"\?[^\s\"']*")

QueryValue = Union[str, int]


def redact_api_key(api_key: str) -> str:
    """``"lsk_test_abc…"`` → ``"lsk_test_****"``. Ne révèle jamais un caractère de la partie secrète."""
    match = _KEY_PREFIX.match(api_key)
    return f"{match.group(1)}****" if match else "****"


def redact_path(path: str, query: Optional[Sequence[Tuple[str, QueryValue]]]) -> str:
    """Chemin + requête, numéros de téléphone masqués."""
    if not query:
        return path
    parts = []
    for name, value in query:
        shown = mask(str(value)) if name in PHONE_PARAMS else quote(str(value), safe="")
        parts.append(f"{quote(name, safe='')}={shown}")
    return f"{path}?{'&'.join(parts)}"


def strip_query_strings(text: str) -> str:
    """Retire la requête d'une URL citée dans un message d'erreur (elle peut contenir un numéro)."""
    return _QUERY.sub("?…", text)
