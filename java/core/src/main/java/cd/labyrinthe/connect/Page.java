package cd.labyrinthe.connect;

import java.util.Collections;
import java.util.Iterator;
import java.util.List;
import java.util.NoSuchElementException;
import java.util.Optional;
import java.util.Spliterator;
import java.util.Spliterators;
import java.util.function.IntFunction;
import java.util.stream.Stream;
import java.util.stream.StreamSupport;

/**
 * One page of a list, e.g. {@code client.sms().list(...)}.
 *
 * <pre>{@code
 * Page<Message> page = client.sms().list(ListSmsParams.builder().status(MessageStatus.FAILED).build());
 * page.data(); page.total(); page.hasMore(); page.nextPage();
 * for (Message message : page.autoPagingIterable()) {   // every item of every page, fetched lazily
 *   ...
 * }
 * }</pre>
 *
 * @param <T> item type
 */
public final class Page<T> {

  private final List<T> data;
  private final int currentPage;
  private final int lastPage;
  private final int perPage;
  private final long total;
  private final IntFunction<Page<T>> fetchPage;

  Page(
      List<T> data,
      int currentPage,
      int lastPage,
      int perPage,
      long total,
      IntFunction<Page<T>> fetchPage) {
    this.data = Collections.unmodifiableList(List.copyOf(data));
    this.currentPage = currentPage;
    this.lastPage = lastPage;
    this.perPage = perPage;
    this.total = total;
    this.fetchPage = fetchPage;
  }

  /**
   * Items of this page only.
   *
   * @return unmodifiable list
   */
  public List<T> data() {
    return data;
  }

  /**
   * Number of this page (from 1).
   *
   * @return the page number
   */
  public int currentPage() {
    return currentPage;
  }

  /**
   * Number of the last page.
   *
   * @return the last page number
   */
  public int lastPage() {
    return lastPage;
  }

  /**
   * Page size.
   *
   * @return items per page
   */
  public int perPage() {
    return perPage;
  }

  /**
   * Number of items across every page.
   *
   * @return the total
   */
  public long total() {
    return total;
  }

  /**
   * Whether a next page exists.
   *
   * @return true when {@link #nextPage()} fetches a page
   */
  public boolean hasMore() {
    return currentPage < lastPage;
  }

  /**
   * Fetches the next page (one API call).
   *
   * @return the next page, or empty on the last one
   */
  public Optional<Page<T>> nextPage() {
    return hasMore() ? Optional.of(fetchPage.apply(currentPage + 1)) : Optional.empty();
  }

  /**
   * Every item from this page to the last one; pages are fetched lazily while iterating.
   *
   * @return an iterable that can be iterated several times (each time from this page)
   */
  public Iterable<T> autoPagingIterable() {
    return AutoPagingIterator::new;
  }

  /**
   * Every item from this page to the last one, as a sequential lazy stream.
   *
   * @return the stream
   */
  public Stream<T> autoPagingStream() {
    return StreamSupport.stream(
        Spliterators.spliteratorUnknownSize(
            new AutoPagingIterator(), Spliterator.ORDERED | Spliterator.NONNULL),
        false);
  }

  @Override
  public String toString() {
    return "Page(currentPage="
        + currentPage
        + ", lastPage="
        + lastPage
        + ", perPage="
        + perPage
        + ", total="
        + total
        + ", items="
        + data.size()
        + ")";
  }

  private final class AutoPagingIterator implements Iterator<T> {
    private Page<T> page = Page.this;
    private Iterator<T> items = Page.this.data.iterator();

    @Override
    public boolean hasNext() {
      while (!items.hasNext()) {
        if (!page.hasMore()) {
          return false;
        }
        Page<T> next = page.fetchPage.apply(page.currentPage + 1);
        if (next.currentPage <= page.currentPage) {
          return false; // pagination incohérente : on s'arrête plutôt que de boucler
        }
        page = next;
        items = page.data.iterator();
      }
      return true;
    }

    @Override
    public T next() {
      if (!hasNext()) {
        throw new NoSuchElementException();
      }
      return items.next();
    }
  }
}
