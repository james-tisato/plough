select
	 p.Name
    ,COUNT(distinct m.MatchId) as Total
    ,SUM(CASE m.CompetitionType WHEN 'League' THEN 1 ELSE 0 END) as League
    ,SUM(CASE m.CompetitionType WHEN 'Friendly' THEN 1 ELSE 0 END) as Friendly
from Player p
inner join PlayerPerformance pp on pp.PlayerId = p.PlayerId
INNER JOIN Match m ON m.MatchId = pp.MatchId
LEFT JOIN CareerFieldingSummaryBase b on b.PlayerId = p.PlayerId
where
		b.CareerFieldingSummaryBaseId is null
group by p.Name
Order by Total desc, League desc, Friendly desc