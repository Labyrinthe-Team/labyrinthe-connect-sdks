package cd.labyrinthe.connect;

import cd.labyrinthe.connect.internal.Transport;

/**
 * Send and track SMS ({@code client.sms()}). Thread-safe.
 *
 * <p>Every method throws a {@link cd.labyrinthe.connect.exception.LabyrintheException} subclass on
 * failure.
 */
public final class SmsService {

  private final Transport transport;
  private final ApiMapper mapper;

  SmsService(Transport transport, ApiMapper mapper) {
    this.transport = transport;
    this.mapper = mapper;
  }

  /**
   * Sends one SMS. See {@link #send(SendSmsParams, RequestOptions)}.
   *
   * @param to recipient, in any accepted format
   * @param body text, 1 to {@value Limits#MAX_BODY_LENGTH} characters
   * @return the queued message
   */
  public Message send(String to, String body) {
    return send(SendSmsParams.builder().to(to).body(body).build());
  }

  /**
   * Sends one SMS. See {@link #send(SendSmsParams, RequestOptions)}.
   *
   * @param params recipient, text and options
   * @return the queued message
   */
  public Message send(SendSmsParams params) {
    return send(params, RequestOptions.none());
  }

  /**
   * Sends one SMS. Asynchronous on the API side: the returned message is {@code queued}; follow its
   * status with webhooks ({@code message.status_changed}) or {@link #get(String)}.
   *
   * <p>An {@code Idempotency-Key} is always sent (a random UUID when you give none), so network
   * errors are retried safely: the SMS is never sent twice.
   *
   * @param params recipient, text and options
   * @param options per-request options
   * @return the queued message
   */
  public Message send(SendSmsParams params, RequestOptions options) {
    ApiMapper.Dispatch dispatch = mapper.send(params, options);
    return ApiMapper.first(
        ApiMapper.batch(transport.send(dispatch.request), dispatch.idempotencyKey));
  }

  /**
   * Sends the same SMS to several recipients, all or nothing. See {@link
   * #sendMany(SendManySmsParams, RequestOptions)}.
   *
   * @param params recipients, text and options
   * @return one message per distinct recipient
   */
  public MessageBatch sendMany(SendManySmsParams params) {
    return sendMany(params, RequestOptions.none());
  }

  /**
   * Sends the same SMS to up to {@value Limits#MAX_RECIPIENTS} recipients, all or nothing.
   *
   * <p>If a single recipient is refused, nothing is sent and a {@link
   * cd.labyrinthe.connect.exception.RecipientNotSendableException} lists every refused number.
   * Duplicates are merged by the API (one message per distinct number). Numbers the SDK can already
   * tell are invalid are refused locally ({@link
   * cd.labyrinthe.connect.exception.InvalidArgumentException}, with their index only).
   *
   * @param params recipients, text and options
   * @param options per-request options
   * @return one message per distinct recipient
   */
  public MessageBatch sendMany(SendManySmsParams params, RequestOptions options) {
    ApiMapper.Dispatch dispatch = mapper.sendMany(params, options);
    return ApiMapper.batch(transport.send(dispatch.request), dispatch.idempotencyKey);
  }

  /**
   * Computes encoding, segments and credits without sending anything.
   *
   * @param params text and recipients
   * @return the estimate
   */
  public Estimate estimate(EstimateSmsParams params) {
    return estimate(params, RequestOptions.none());
  }

  /**
   * Computes encoding, segments and credits without sending anything.
   *
   * @param params text and recipients
   * @param options per-request options
   * @return the estimate
   */
  public Estimate estimate(EstimateSmsParams params, RequestOptions options) {
    return ApiMapper.estimate(transport.send(mapper.estimate(params, options)));
  }

  /**
   * One message of the account, by id.
   *
   * @param id message id
   * @return the message
   * @throws cd.labyrinthe.connect.exception.NotFoundException when unknown
   */
  public Message get(String id) {
    return get(id, RequestOptions.none());
  }

  /**
   * One message of the account, by id.
   *
   * @param id message id
   * @param options per-request options
   * @return the message
   * @throws cd.labyrinthe.connect.exception.NotFoundException when unknown
   */
  public Message get(String id, RequestOptions options) {
    return ApiMapper.message(transport.send(ApiMapper.get(id, options)));
  }

  /**
   * First page of the messages sent with this API key, newest first.
   *
   * @return the first page
   */
  public Page<Message> list() {
    return list(ListSmsParams.defaults());
  }

  /**
   * Messages sent with this API key, newest first (one page; use {@link Page#autoPagingIterable()}
   * for every page).
   *
   * @param params filters and pagination
   * @return the requested page
   */
  public Page<Message> list(ListSmsParams params) {
    return list(params, RequestOptions.none());
  }

  /**
   * Messages sent with this API key, newest first.
   *
   * @param params filters and pagination
   * @param options per-request options (applied to every page)
   * @return the requested page
   */
  public Page<Message> list(ListSmsParams params, RequestOptions options) {
    return fetch(params, params.page(), options);
  }

  private Page<Message> fetch(ListSmsParams params, int page, RequestOptions options) {
    ApiMapper.PageValues values =
        ApiMapper.page(transport.send(mapper.list(params, page, options)), page);
    return new Page<>(
        values.data,
        values.currentPage,
        values.lastPage,
        values.perPage,
        values.total,
        next -> fetch(params, next, options));
  }

  @Override
  public String toString() {
    return "SmsService(" + transport + ")";
  }
}
