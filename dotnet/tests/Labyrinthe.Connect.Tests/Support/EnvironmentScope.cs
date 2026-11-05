using System;
using System.Collections.Generic;
using Xunit;

namespace Labyrinthe.Connect.Tests.Support;

/// <summary>Modifie des variables d'environnement le temps d'un test, puis les restaure.</summary>
public sealed class EnvironmentScope : IDisposable
{
    private readonly List<(string Name, string? Value)> previous = new();

    public EnvironmentScope(params (string Name, string? Value)[] variables)
    {
        foreach (var (name, value) in variables)
        {
            previous.Add((name, Environment.GetEnvironmentVariable(name)));
            Environment.SetEnvironmentVariable(name, value);
        }
    }

    public void Dispose()
    {
        foreach (var (name, value) in previous)
        {
            Environment.SetEnvironmentVariable(name, value);
        }
    }
}

/// <summary>Les tests qui touchent l'environnement ne tournent pas en parallèle les uns des autres.</summary>
[CollectionDefinition(Name, DisableParallelization = true)]
public sealed class EnvironmentGroup
{
    public const string Name = "Environment";
}
