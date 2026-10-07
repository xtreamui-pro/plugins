// Command setup prepares a THROWAWAY database for plugins/e2e/run.sh: a
// reseller with credits whose group may create sub-resellers, and its API
// key (printed). It refuses any database whose name lacks "test" or "verify".
package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"strconv"
	"strings"

	"gorm.io/driver/postgres"
	"gorm.io/gorm"

	"xtreampro/internal/app/models"
	"xtreampro/internal/app/usecase"
)

func main() {
	db, err := gorm.Open(postgres.Open(os.Getenv("DATABASE_URL")), &gorm.Config{})
	if err != nil {
		log.Fatal(err)
	}
	var name string
	if err := db.Raw("SELECT current_database()").Scan(&name).Error; err != nil {
		log.Fatal(err)
	}
	if !strings.Contains(name, "test") && !strings.Contains(name, "verify") {
		log.Fatalf("refusing to touch database %q: its name must contain \"test\" or \"verify\"", name)
	}
	ctx := context.Background()
	var res, sub models.Group
	if err := db.Where("name = ?", "Resellers").First(&res).Error; err != nil {
		log.Fatal(err)
	}
	if err := db.Where("name = ?", "Sub Resellers").First(&sub).Error; err != nil {
		log.Fatal(err)
	}
	if err := db.Model(&res).Update("SubResellers", strconv.FormatInt(sub.ID, 10)).Error; err != nil {
		log.Fatal(err)
	}
	// A package for MAG boxes only: the panel lists it with sells ["mag"] and refuses to sell it as a plain
	// line, so the connectors' package pickers must leave it out (sells).
	box := models.Package{Name: "E2E box only", IsOfficial: true, OfficialCredits: 10, OfficialDuration: 1, OfficialDurationIn: "months",
		OnlyMAG: true, MaxConnections: 1, Groups: strconv.FormatInt(res.ID, 10),
		// listed last: the other harnesses take the first official package
		Position: 9999}
	if err := db.Where("name = ?", box.Name).FirstOrCreate(&box).Error; err != nil {
		log.Fatal(err)
	}
	users := usecase.NewUserService(db)
	u := &models.User{Username: "e2e_reseller", Email: "e2e@example.test", Password: "e2e-password-123", Role: "reseller", Status: "active", GroupID: &res.ID, Credits: 100000}
	if err := users.Create(ctx, u); err != nil {
		if err := db.Where("username = ?", "e2e_reseller").First(u).Error; err != nil {
			log.Fatal(err)
		}
	}
	db.Model(u).Update("credits", 100000)
	key, err := users.RotateAPIKey(ctx, u.ID)
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(key)
}
