from __future__ import annotations

from typing import Any, Sequence

import pytest

from .helpers import Harness, HarnessFactory, Responder


@pytest.fixture(params=["sync", "async"])
def harness(request: pytest.FixtureRequest) -> HarnessFactory:
    """Fabrique un client simulé ; chaque test qui l'utilise tourne en synchrone ET en asynchrone."""
    kind: str = request.param

    def factory(responses: Sequence[Responder], **options: Any) -> Harness:
        return Harness(kind, responses, **options)

    return factory


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch: pytest.MonkeyPatch) -> None:
    """Les variables d'environnement de l'intégration ne doivent pas influencer les tests unitaires."""
    for name in ("LABYRINTHE_API_KEY", "LABYRINTHE_BASE_URL", "LABYRINTHE_WEBHOOK_SECRET"):
        monkeypatch.delenv(name, raising=False)
