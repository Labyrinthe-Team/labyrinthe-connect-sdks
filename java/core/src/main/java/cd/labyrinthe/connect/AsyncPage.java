package cd.labyrinthe.connect;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Optional;
import java.util.concurrent.CompletableFuture;
import java.util.function.Consumer;
import java.util.function.IntFunction;

/**
 * One page of a list, from the asynchronous client.
 *
 * <pre>{@code
 * client.async().sms().list(ListSmsParams.builder().reference("order-1042").build())
 *     .thenCompose(page -> page.autoPagingForEach(message -> handle(message)));
 * }</pre>
 *
 * @param <T> item type
 */
public final class AsyncPage<T> {

  private final List<T> data;
  private final int currentPage;
  private final int lastPage;
  private final int perPage;
  private final long total;
  private final IntFunction<CompletableFuture<AsyncPage<T>>> fetchPage;

  AsyncPage(
      List<T> data,
      int currentPage,
      int lastPage,
      int perPage,
      long total,
      IntFunction<CompletableFuture<AsyncPage<T>>> fetchPage) {
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
  public CompletableFuture<Optional<AsyncPage<T>>> nextPage() {
    return hasMore()
        ? fetchPage.apply(currentPage + 1).thenApply(Optional::of)
        : CompletableFuture.completedFuture(Optional.empty());
  }

  /**
   * Calls {@code action} for every item from this page to the last one, fetching pages one after
   * the other.
   *
   * @param action called for each item, in order
   * @return completes when every page was processed
   */
  public CompletableFuture<Void> autoPagingForEach(Consumer<? super T> action) {
    data.forEach(action);
    if (!hasMore()) {
      return CompletableFuture.completedFuture(null);
    }
    return fetchPage
        .apply(currentPage + 1)
        .thenCompose(
            next ->
                next.currentPage <= currentPage
                    ? CompletableFuture.<Void>completedFuture(null)
                    : next.autoPagingForEach(action));
  }

  /**
   * Every item from this page to the last one, in one list. Prefer {@link
   * #autoPagingForEach(Consumer)} for long lists.
   *
   * @return the items
   */
  public CompletableFuture<List<T>> autoPagingToList() {
    List<T> items = Collections.synchronizedList(new ArrayList<>());
    return autoPagingForEach(items::add)
        .thenApply(ignored -> Collections.unmodifiableList(new ArrayList<>(items)));
  }

  @Override
  public String toString() {
    return "AsyncPage(currentPage="
        + currentPage
        + ", lastPage="
        + lastPage
        + ", total="
        + total
        + ", items="
        + data.size()
        + ")";
  }
}
