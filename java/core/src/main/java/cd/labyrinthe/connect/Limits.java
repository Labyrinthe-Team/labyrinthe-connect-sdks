package cd.labyrinthe.connect;

/** Limits of the API, checked by the SDK before any call (and against the spec by the tests). */
public final class Limits {

  /** Maximum length of an SMS body: {@value}. */
  public static final int MAX_BODY_LENGTH = 1600;

  /** Maximum number of recipients per send: {@value}. */
  public static final int MAX_RECIPIENTS = 1000;

  /** Maximum length of a Sender ID: {@value}. */
  public static final int MAX_SENDER_LENGTH = 11;

  /** Maximum length of a reference: {@value}. */
  public static final int MAX_REFERENCE_LENGTH = 64;

  /** Maximum length of an {@code Idempotency-Key}: {@value}. */
  public static final int MAX_IDEMPOTENCY_KEY_LENGTH = 255;

  /** Maximum page size of {@code sms().list()}: {@value}. */
  public static final int MAX_PER_PAGE = 100;

  /** Default page size of {@code sms().list()}: {@value}. */
  public static final int DEFAULT_PER_PAGE = 15;

  /** Maximum length of an OTP template: {@value}. */
  public static final int MAX_OTP_TEMPLATE_LENGTH = 1600;

  /** Maximum length of an OTP code: {@value}. */
  public static final int MAX_OTP_CODE_LENGTH = 20;

  private Limits() {}
}
