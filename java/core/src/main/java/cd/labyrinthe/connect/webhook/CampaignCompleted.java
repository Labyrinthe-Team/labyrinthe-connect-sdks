package cd.labyrinthe.connect.webhook;

import java.time.Instant;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Objects;
import java.util.Optional;

/** {@code campaign.completed}: every message of a campaign reached a final status. */
public final class CampaignCompleted extends WebhookEvent {

  private final Data data;

  CampaignCompleted(String id, String type, Instant timestamp, Map<String, Object> raw, Data data) {
    super(id, type, timestamp, raw);
    this.data = Objects.requireNonNull(data, "data");
  }

  @Override
  public WebhookEventType eventType() {
    return WebhookEventType.CAMPAIGN_COMPLETED;
  }

  /**
   * Summary of the campaign.
   *
   * @return the data
   */
  public Data data() {
    return data;
  }

  /** Content of a {@code campaign.completed} event. */
  public static final class Data {
    private final String campaignId;
    private final String name;
    private final long recipientsCount;
    private final Map<String, Long> messagesByStatus;
    private final long creditsCharged;
    private final Instant completedAt;

    Data(
        String campaignId,
        String name,
        long recipientsCount,
        Map<String, Long> messagesByStatus,
        long creditsCharged,
        Instant completedAt) {
      this.campaignId = Objects.requireNonNull(campaignId, "campaignId");
      this.name = Objects.requireNonNull(name, "name");
      this.recipientsCount = recipientsCount;
      this.messagesByStatus = Collections.unmodifiableMap(new LinkedHashMap<>(messagesByStatus));
      this.creditsCharged = creditsCharged;
      this.completedAt = completedAt;
    }

    /**
     * Id of the campaign.
     *
     * @return the campaign id
     */
    public String campaignId() {
      return campaignId;
    }

    /**
     * Name of the campaign.
     *
     * @return the name
     */
    public String name() {
      return name;
    }

    /**
     * Number of recipients.
     *
     * @return the count
     */
    public long recipientsCount() {
      return recipientsCount;
    }

    /**
     * Number of messages per final status, e.g. {@code {"delivered": 1150, "failed": 30}}.
     *
     * @return unmodifiable map
     */
    public Map<String, Long> messagesByStatus() {
      return messagesByStatus;
    }

    /**
     * Credits charged for the campaign.
     *
     * @return the credits
     */
    public long creditsCharged() {
      return creditsCharged;
    }

    /**
     * Completion time.
     *
     * @return the time, if given
     */
    public Optional<Instant> completedAt() {
      return Optional.ofNullable(completedAt);
    }

    @Override
    public String toString() {
      return "CampaignCompleted.Data(campaignId="
          + campaignId
          + ", recipientsCount="
          + recipientsCount
          + ", messagesByStatus="
          + messagesByStatus
          + ")";
    }
  }
}
