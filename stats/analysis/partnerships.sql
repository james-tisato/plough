SELECT
     CASE part.Wicket 
         WHEN 1 THEN "1st" 
         WHEN 2 THEN "2nd" 
         WHEN 3 THEN "3rd" 
         ELSE (CAST(part.Wicket AS TEXT) || "th") 
     END
    ,(CAST(part.Runs AS TEXT) || CASE part.NotOut WHEN 1 THEN "*" ELSE "" END)
    ,CASE WHEN bpi.Position < bpo.Position THEN pi.Name ELSE po.Name END
    ,CASE 
        WHEN bpi.Position < bpo.Position THEN 
            CAST(bpi.Runs AS TEXT) || (CASE bpi.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
        ELSE
            CAST(bpo.Runs AS TEXT) || (CASE bpo.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
    END
    ,CASE WHEN bpi.Position < bpo.Position THEN po.Name ELSE pi.Name END
    ,CASE 
        WHEN bpi.Position < bpo.Position THEN 
            CAST(bpo.Runs AS TEXT) || (CASE bpo.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)            
        ELSE
            CAST(bpi.Runs AS TEXT) || (CASE bpi.HowOut WHEN "not out" THEN "*" WHEN "retired hurt" THEN "*" WHEN "retired not out" THEN "*" ELSE "" END)
    END
    ,m.OppoClubName
    ,m.PloughTeamName
    ,m.CompetitionType
    ,STRFTIME('%d-%m-%Y', m.MatchDate)
FROM BattingPartnership part
INNER JOIN BattingPerformance bpo ON bpo.BattingPerformanceId = part.BattingPerformanceIdOut
INNER JOIN Player po ON po.PlayerId = bpo.PlayerId
INNER JOIN BattingPerformance bpi ON bpi.BattingPerformanceId = part.BattingPerformanceIdIn
INNER JOIN Player pi ON pi.PlayerId = bpi.PlayerId
INNER JOIN PlayerPerformance ppo ON ppo.PlayerPerformanceId = bpo.PlayerPerformanceId
INNER JOIN Match m ON m.MatchId = ppo.MatchId
WHERE
        Wicket = 1
    AND Season = 2021
ORDER BY part.Runs DESC, part.NotOut DESC
LIMIT 100