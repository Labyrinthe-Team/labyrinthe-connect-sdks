using System;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class PhoneNumberTests
{
    [Theory]
    [InlineData("243812345678", "243812345678")]
    [InlineData("+243812345678", "243812345678")]
    [InlineData("+243 81 234 5678", "243812345678")]
    [InlineData("00243812345678", "243812345678")]
    [InlineData("0812345678", "243812345678")]
    [InlineData("812345678", "243812345678")]
    [InlineData("081-234-5678", "243812345678")]
    [InlineData("(081) 234 56 78", "243812345678")]
    [InlineData("+33 6 12 34 56 78", "33612345678")]
    [InlineData("+1 415 555 2671", "14155552671")]
    public void Normalizes_like_the_api(string input, string expected)
    {
        Assert.Equal(expected, PhoneNumber.Normalize(input));
        Assert.True(PhoneNumber.IsValid(input));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("12")]
    [InlineData("abc")]
    [InlineData("+1234567890123456")]
    public void Rejects_impossible_numbers(string? input)
    {
        Assert.Null(PhoneNumber.Normalize(input));
        Assert.False(PhoneNumber.IsValid(input));
    }

    [Fact]
    public void Uses_another_default_country_code()
    {
        Assert.Equal("33612345678", PhoneNumber.Normalize("0612345678", "33"));
        Assert.Throws<ArgumentException>(() => PhoneNumber.Normalize("0812345678", "+243"));
        Assert.Throws<ArgumentException>(() => PhoneNumber.Normalize("0812345678", "1234"));
        Assert.Throws<ArgumentException>(() => PhoneNumber.Normalize("0812345678", ""));
    }

    [Theory]
    [InlineData("243812345678", "24381*****78")]
    [InlineData("+243 81 234 5678", "24381*****78")]
    [InlineData("33612345678", "3361*****78")]
    [InlineData("12345", "1**45")]
    [InlineData("1234", "****")]
    [InlineData("", "")]
    [InlineData(null, "")]
    public void Masks_numbers_for_logs(string? input, string expected) => Assert.Equal(expected, PhoneNumber.Mask(input));
}
