/** One page of a paginated list. Iterate it with `for await` to walk this page and the following ones. */
export class Page<T> implements AsyncIterable<T> {
  readonly data: T[];
  readonly currentPage: number;
  readonly lastPage: number;
  readonly perPage: number;
  readonly total: number;
  readonly #fetchPage: (page: number) => Promise<Page<T>>;

  /** @internal */
  constructor(
    meta: { data: T[]; currentPage: number; lastPage: number; perPage: number; total: number },
    fetchPage: (page: number) => Promise<Page<T>>,
  ) {
    this.data = meta.data;
    this.currentPage = meta.currentPage;
    this.lastPage = meta.lastPage;
    this.perPage = meta.perPage;
    this.total = meta.total;
    this.#fetchPage = fetchPage;
  }

  get hasMore(): boolean {
    return this.currentPage < this.lastPage;
  }

  /** The next page, or null on the last one. */
  async nextPage(): Promise<Page<T> | null> {
    return this.hasMore ? this.#fetchPage(this.currentPage + 1) : null;
  }

  /** Every item from this page to the last one (pages are fetched lazily). */
  async *[Symbol.asyncIterator](): AsyncIterator<T> {
    yield* this.data;
    let page = await this.nextPage();
    while (page !== null) {
      yield* page.data;
      page = await page.nextPage();
    }
  }

  toJSON(): Record<string, unknown> {
    return {
      data: this.data,
      currentPage: this.currentPage,
      lastPage: this.lastPage,
      perPage: this.perPage,
      total: this.total,
    };
  }
}

/**
 * Result of a list call: `await` it for the first page, or iterate it with
 * `for await` to walk every item of every page. The first request is sent
 * when the result is awaited or iterated.
 */
export class PagePromise<T> implements PromiseLike<Page<T>>, AsyncIterable<T> {
  readonly #load: () => Promise<Page<T>>;
  #promise: Promise<Page<T>> | undefined;

  /** @internal */
  constructor(load: () => Promise<Page<T>>) {
    this.#load = load;
  }

  #first(): Promise<Page<T>> {
    this.#promise ??= this.#load();
    return this.#promise;
  }

  then<R1 = Page<T>, R2 = never>(
    onFulfilled?: ((page: Page<T>) => R1 | PromiseLike<R1>) | null,
    onRejected?: ((reason: unknown) => R2 | PromiseLike<R2>) | null,
  ): Promise<R1 | R2> {
    return this.#first().then(onFulfilled, onRejected);
  }

  catch<R = never>(onRejected?: ((reason: unknown) => R | PromiseLike<R>) | null): Promise<Page<T> | R> {
    return this.#first().catch(onRejected);
  }

  finally(onFinally?: (() => void) | null): Promise<Page<T>> {
    return this.#first().finally(onFinally);
  }

  async *[Symbol.asyncIterator](): AsyncIterator<T> {
    yield* await this.#first();
  }
}
