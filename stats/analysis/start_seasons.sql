select
     p.Name
    --,p.PcPlayerId
    --,p.PlayerId
    ,min(m.Season) as FirstSeason    
    ,min(m.MatchDate) as FirstMatch
from Player p
inner join PlayerPerformance pp on pp.PlayerId = p.PlayerId
inner join Match m on m.MatchId = pp.MatchId
left join CareerMatchesSummaryBase cb on cb.PlayerId = p.PlayerId
where 1=1
    and cb.CareerMatchesSummaryBaseId is null
    and p.PcPlayerId != -1
group by p.Name
order by FirstMatch, p.Name