package cd.labyrinthe.connect;

import cd.labyrinthe.connect.exception.LabyrintheException;
import cd.labyrinthe.connect.internal.Transport;
import java.util.concurrent.CompletableFuture;

/**
 * Send and track SMS, asynchronously ({@code client.async().sms()}). Same behaviour as {@link
 * SmsService}; futures fail with a {@link LabyrintheException} (wrapped in a {@link
 * java.util.concurrent.CompletionException} by {@code join()}).
 */
public final class AsyncSmsService {

  private final Transport transport;
  private final ApiMapper mapper;

  AsyncSmsService(Transport transport, ApiMapper mapper) {
    this.transport = transport;
    this.mapper = mapper;
  }

  /**
   * Sends one SMS. See {@link SmsService#send(SendSmsParams, RequestOptions)}.
   *
   * @param to recipient
   * @param body text
   * @return the queued message
   */
  public CompletableFuture<Message> send(String to, String body) {
    try {
      return send(SendSmsParams.builder().to(to).body(body).build());
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  /**
   * Sends one SMS. See {@link SmsService#send(SendSmsParams, RequestOptions)}.
   *
   * @param params recipient, text and options
   * @return the queued message
   */
  public CompletableFuture<Message> send(SendSmsParams params) {
    return send(params, RequestOptions.none());
  }

  /**
   * Sends one SMS. See {@link SmsService#send(SendSmsParams, RequestOptions)}.
   *
   * @param params recipient, text and options
   * @param options per-request options
   * @return the queued message
   */
  public CompletableFuture<Message> send(SendSmsParams params, RequestOptions options) {
    ApiMapper.Dispatch dispatch;
    try {
      dispatch = mapper.send(params, options);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
    return transport
        .sendAsync(dispatch.request)
        .thenApply(r -> ApiMapper.first(ApiMapper.batch(r, dispatch.idempotencyKey)));
  }

  /**
   * Sends the same SMS to several recipients, all or nothing. See {@link
   * SmsService#sendMany(SendManySmsParams, RequestOptions)}.
   *
   * @param params recipients, text and options
   * @return one message per distinct recipient
   */
  public CompletableFuture<MessageBatch> sendMany(SendManySmsParams params) {
    return sendMany(params, RequestOptions.none());
  }

  /**
   * Sends the same SMS to several recipients, all or nothing. See {@link
   * SmsService#sendMany(SendManySmsParams, RequestOptions)}.
   *
   * @param params recipients, text and options
   * @param options per-request options
   * @return one message per distinct recipient
   */
  public CompletableFuture<MessageBatch> sendMany(
      SendManySmsParams params, RequestOptions options) {
    ApiMapper.Dispatch dispatch;
    try {
      dispatch = mapper.sendMany(params, options);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
    return transport
        .sendAsync(dispatch.request)
        .thenApply(r -> ApiMapper.batch(r, dispatch.idempotencyKey));
  }

  /**
   * Computes encoding, segments and credits without sending anything.
   *
   * @param params text and recipients
   * @return the estimate
   */
  public CompletableFuture<Estimate> estimate(EstimateSmsParams params) {
    return estimate(params, RequestOptions.none());
  }

  /**
   * Computes encoding, segments and credits without sending anything.
   *
   * @param params text and recipients
   * @param options per-request options
   * @return the estimate
   */
  public CompletableFuture<Estimate> estimate(EstimateSmsParams params, RequestOptions options) {
    try {
      return transport.sendAsync(mapper.estimate(params, options)).thenApply(ApiMapper::estimate);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  /**
   * One message of the account, by id.
   *
   * @param id message id
   * @return the message (fails with {@link cd.labyrinthe.connect.exception.NotFoundException})
   */
  public CompletableFuture<Message> get(String id) {
    return get(id, RequestOptions.none());
  }

  /**
   * One message of the account, by id.
   *
   * @param id message id
   * @param options per-request options
   * @return the message
   */
  public CompletableFuture<Message> get(String id, RequestOptions options) {
    try {
      return transport.sendAsync(ApiMapper.get(id, options)).thenApply(ApiMapper::message);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
  }

  /**
   * First page of the messages sent with this API key.
   *
   * @return the first page
   */
  public CompletableFuture<AsyncPage<Message>> list() {
    return list(ListSmsParams.defaults());
  }

  /**
   * Messages sent with this API key, newest first (use {@link
   * AsyncPage#autoPagingForEach(java.util.function.Consumer)} for every page).
   *
   * @param params filters and pagination
   * @return the requested page
   */
  public CompletableFuture<AsyncPage<Message>> list(ListSmsParams params) {
    return list(params, RequestOptions.none());
  }

  /**
   * Messages sent with this API key, newest first.
   *
   * @param params filters and pagination
   * @param options per-request options (applied to every page)
   * @return the requested page
   */
  public CompletableFuture<AsyncPage<Message>> list(ListSmsParams params, RequestOptions options) {
    return fetch(params, params.page(), options);
  }

  private CompletableFuture<AsyncPage<Message>> fetch(
      ListSmsParams params, int page, RequestOptions options) {
    Transport.Request request;
    try {
      request = mapper.list(params, page, options);
    } catch (LabyrintheException e) {
      return CompletableFuture.failedFuture(e);
    }
    return transport
        .sendAsync(request)
        .thenApply(
            response -> {
              ApiMapper.PageValues values = ApiMapper.page(response, page);
              return new AsyncPage<>(
                  values.data,
                  values.currentPage,
                  values.lastPage,
                  values.perPage,
                  values.total,
                  next -> fetch(params, next, options));
            });
  }

  @Override
  public String toString() {
    return "AsyncSmsService(" + transport + ")";
  }
}
