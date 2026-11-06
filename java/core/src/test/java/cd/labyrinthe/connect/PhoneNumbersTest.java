package cd.labyrinthe.connect;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import cd.labyrinthe.connect.exception.InvalidArgumentException;
import java.util.Optional;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.CsvSource;
import org.junit.jupiter.params.provider.ValueSource;

class PhoneNumbersTest {

  @ParameterizedTest
  @CsvSource({
    "'+243 81 234 5678', 243812345678",
    "00243812345678, 243812345678",
    "243812345678, 243812345678",
    "0812345678, 243812345678",
    "812345678, 243812345678",
    "'081-234-5678', 243812345678",
    "'(081) 234 5678', 243812345678",
    "'+33 6 12 34 56 78', 33612345678",
    "0033612345678, 33612345678",
    "'+1 415 555 2671', 14155552671",
    // API 1.2.0 : sans « + », 11 chiffres ou plus ne commençant pas par 0 = déjà international.
    "33612345678, 33612345678",
    "'33 6 12 34 56 78', 33612345678",
    "14155552671, 14155552671",
    "447911123456, 447911123456",
    "2250701020304, 2250701020304",
    // Formats RDC inchangés.
    "0812345678, 243812345678",
    "'081 234 5678', 243812345678",
    "899999999, 243899999999",
    // 10 chiffres ou moins sans « + » : format local, l'indicatif par défaut est ajouté.
    "4155552671, 2434155552671",
  })
  void normalisesLikeTheApi(String raw, String expected) {
    assertEquals(Optional.of(expected), PhoneNumbers.normalize(raw));
  }

  @ParameterizedTest
  @ValueSource(strings = {"", "   ", "abc", "12", "+", "+1234567", "+1234567890123456"})
  void rejectsWhatCannotBeAPhoneNumber(String raw) {
    assertFalse(PhoneNumbers.normalize(raw).isPresent());
    assertFalse(PhoneNumbers.isValid(raw));
  }

  @Test
  void normalisingANormalisedNumberDoesNotChangeIt() {
    for (String raw :
        new String[] {
          "+243 81 234 5678", "0812345678", "+33 6 12 34 56 78", "+1 415 555 2671", "812345678"
        }) {
      String once = PhoneNumbers.normalize(raw).orElseThrow();
      assertEquals(Optional.of(once), PhoneNumbers.normalize(once), raw);
    }
  }

  @Test
  void usesTheGivenCountryCode() {
    assertEquals(Optional.of("33612345678"), PhoneNumbers.normalize("0612345678", "33"));
    assertEquals(Optional.of("243812345678"), PhoneNumbers.normalize("+243812345678", "33"));
    assertFalse(PhoneNumbers.normalize(null).isPresent());
    assertThrows(InvalidArgumentException.class, () -> PhoneNumbers.normalize("1", "+243"));
    assertThrows(InvalidArgumentException.class, () -> PhoneNumbers.normalize("1", "0243"));
    assertThrows(InvalidArgumentException.class, () -> PhoneNumbers.normalize("1", null));
  }

  @Test
  void masksNumbers() {
    assertEquals("24381*****78", PhoneNumbers.mask("243812345678"));
    assertEquals("24381*****78", PhoneNumbers.mask("+243 812 345 678"));
    assertEquals("3361*****78", PhoneNumbers.mask("33612345678"));
    assertEquals("1*****78", PhoneNumbers.mask("12345678"));
    assertEquals("****", PhoneNumbers.mask("1234"));
    assertEquals("", PhoneNumbers.mask(null));
    assertTrue(PhoneNumbers.isValid("0812345678"));
  }
}
