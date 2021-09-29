select
     p.Name
    ,matches.Matches
    ,coalesce(bat.Runs, 0) as Runs
    ,coalesce(bat.NotOuts, 0) as NotOuts
    ,coalesce(bat.Fifties, 0) as Fifties
    ,coalesce(bat.Hundreds, 0) as Hundreds
    ,coalesce(bat.Ducks, 0) as Ducks
    ,coalesce(bowl.Wickets, 0) as Wickets
    ,coalesce(bowl.FiveFors, 0) as FiveFors
    ,coalesce(bowl.Maidens, 0) as Maidens
    ,coalesce(field.CatchesFielding, 0)as CatchesFielding
    ,coalesce(field.RunOuts, 0) as RunOuts
    ,coalesce(field.CatchesKeeping, 0) as CatchesKeeping
    ,coalesce(field.Stumpings, 0) as Stumpings
from Player p
left join Active a on a.Player = p.Name
inner join SeasonMatchesSummary matches on matches.PlayerId = p.PlayerId
inner join SeasonBattingSummary bat on bat.PlayerId = p.PlayerId
inner join SeasonBowlingSummary bowl on bowl.PlayerId = p.PlayerId
inner join SeasonFieldingSummary field on field.PlayerId = p.PlayerId
where
        a.Active = 'Y'
    and (
            (matches.Season in (2020) and bat.Season in (2020) and bowl.Season in (2020) and field.Season in (2020) and p.Name not in ('Matt Spencer')) or
            (matches.Season in (2019) and bat.Season in (2019) and bowl.Season in (2019) and field.Season in (2019) and p.Name in ('Matt Spencer'))
        )