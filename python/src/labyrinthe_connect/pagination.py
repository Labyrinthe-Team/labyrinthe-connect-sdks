"""Paginated lists (``client.sms.list()``).

Synchronous::

    page = client.sms.list(status="failed")       # first page
    page.data, page.total, page.has_more
    for message in page.auto_paging_iter():        # every item of every page, fetched lazily
        ...

Asynchronous::

    page = await client.sms.list()                 # first page
    async for message in client.sms.list().auto_paging_iter():
        ...
"""

from __future__ import annotations

from typing import (
    Any,
    AsyncIterator,
    Awaitable,
    Callable,
    Generator,
    Generic,
    Iterator,
    Optional,
    Sequence,
    TypeVar,
)

__all__ = ["AsyncPage", "AsyncPaginator", "SyncPage"]

T = TypeVar("T")


class _PageBase(Generic[T]):
    def __init__(self, data: Sequence[T], current_page: int, last_page: int, per_page: int, total: int) -> None:
        #: Items of this page only.
        self.data: tuple[T, ...] = tuple(data)
        self.current_page = current_page
        self.last_page = last_page
        self.per_page = per_page
        self.total = total

    @property
    def has_more(self) -> bool:
        """True when a next page exists."""
        return self.current_page < self.last_page

    def __repr__(self) -> str:
        return (
            f"{type(self).__name__}(current_page={self.current_page}, last_page={self.last_page}, "
            f"per_page={self.per_page}, total={self.total}, items={len(self.data)})"
        )


class SyncPage(_PageBase[T]):
    """One page of a list. Iterating it walks this page and the following ones."""

    def __init__(
        self,
        data: Sequence[T],
        current_page: int,
        last_page: int,
        per_page: int,
        total: int,
        fetch_page: Callable[[int], SyncPage[T]],
    ) -> None:
        super().__init__(data, current_page, last_page, per_page, total)
        self._fetch_page = fetch_page

    def next_page(self) -> Optional[SyncPage[T]]:
        """The next page, or ``None`` on the last one."""
        return self._fetch_page(self.current_page + 1) if self.has_more else None

    def auto_paging_iter(self) -> Iterator[T]:
        """Every item from this page to the last one (pages are fetched lazily)."""
        page: Optional[SyncPage[T]] = self
        while page is not None:
            yield from page.data
            page = page.next_page()

    def __iter__(self) -> Iterator[T]:
        return self.auto_paging_iter()


class AsyncPage(_PageBase[T]):
    """One page of a list. ``async for`` over it walks this page and the following ones."""

    def __init__(
        self,
        data: Sequence[T],
        current_page: int,
        last_page: int,
        per_page: int,
        total: int,
        fetch_page: Callable[[int], Awaitable[AsyncPage[T]]],
    ) -> None:
        super().__init__(data, current_page, last_page, per_page, total)
        self._fetch_page = fetch_page

    async def next_page(self) -> Optional[AsyncPage[T]]:
        """The next page, or ``None`` on the last one."""
        return await self._fetch_page(self.current_page + 1) if self.has_more else None

    async def auto_paging_iter(self) -> AsyncIterator[T]:
        """Every item from this page to the last one (pages are fetched lazily)."""
        page: Optional[AsyncPage[T]] = self
        while page is not None:
            for item in page.data:
                yield item
            page = await page.next_page()

    def __aiter__(self) -> AsyncIterator[T]:
        return self.auto_paging_iter()


class AsyncPaginator(Generic[T]):
    """Result of an asynchronous list call.

    ``await`` it for the first page, or iterate ``auto_paging_iter()`` (or the
    paginator itself) with ``async for`` to walk every item of every page. The
    first request is sent when the paginator is awaited or iterated.
    """

    def __init__(self, load: Callable[[], Awaitable[AsyncPage[T]]]) -> None:
        self._load = load

    def __await__(self) -> Generator[Any, None, AsyncPage[T]]:
        return self._load().__await__()

    async def auto_paging_iter(self) -> AsyncIterator[T]:
        page = await self._load()
        async for item in page.auto_paging_iter():
            yield item

    def __aiter__(self) -> AsyncIterator[T]:
        return self.auto_paging_iter()
