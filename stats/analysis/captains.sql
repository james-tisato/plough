SELECT
     p.Name
    --,COUNT(pp.PlayerPerformanceId) AS Matches
    ,SUM(CASE WHEN pp.Captain = 1 THEN 1 ELSE 0 END) as MatchesAsCaptain
    ,SUM(CASE WHEN pp.Captain = 1 AND m.Result = 'A' THEN 1 ELSE 0 END) as MatchesAbandoned
    ,SUM(CASE WHEN pp.Captain = 1 AND m.Result != 'A' THEN 1 ELSE 0 END) as MatchesCompleted
    ,SUM(CASE WHEN pp.Captain = 1 AND m.PloughWonMatch THEN 1 ELSE 0 END) as MatchesWon
    ,SUM(CASE WHEN pp.Captain = 1 AND NOT m.PloughWonMatch AND m.Result = 'W' THEN 1 ELSE 0 END) as MatchesLost
    ,ROUND((CAST(SUM(CASE WHEN pp.Captain = 1 AND m.PloughWonMatch THEN 1 ELSE 0 END) AS REAL) / SUM(CASE WHEN pp.Captain = 1 AND m.Result != 'A' THEN 1 ELSE 0 END)) * 100, 1) as MatchWinPercentage
FROM Player p
INNER JOIN PlayerPerformance pp on pp.PlayerId = p.PlayerId
INNER JOIN Match m on m.MatchId = pp.MatchId
WHERE
        m.Season = 2021
GROUP BY Name
HAVING MatchesAsCaptain > 0
ORDER BY MatchesAsCaptain DESC, Name